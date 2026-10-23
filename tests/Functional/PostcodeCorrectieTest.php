<?php

namespace App\Tests\Functional;

use App\Entity\{Aanwezigheidslijst, Afdeling, ChauffeurInstelling, Persoon, Post, Tikking, Voertuig};
use App\Import\LegacyImporter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261023160000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Gebruik uitsluitend een lege, aparte testdatabase. */
final class PostcodeCorrectieTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $ids = [];

    protected function setUp(): void
    {
        self::bootKernel();
        require_once dirname(__DIR__, 2).'/migrations/Version20261023160000.php';
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $afdeling = (new Afdeling())->setSlug('test')->setNaam('Test')->setTitel('Test');
        $niel = (new Post())->setCode('NI')->setNaam('Niel')->setGemeente('Niel')->setPostcode('2845')->setStraat('Volkenbondstraat');
        $nijlen = (new Post())->setCode('NL')->setNaam('Nijlen')->setGemeente('Nijlen')->setPostcode('2560')->setStraat('Kerkstraat');
        $boom = (new Post())->setCode('BO')->setNaam('Boom');
        $nielPersoon = (new Persoon())->setAfdeling($afdeling)->setTiknummer(101)->setNaam('Niel')->setVoornaam('Test')->setCode('unused')->setPost($niel);
        $nijlenPersoon = (new Persoon())->setAfdeling($afdeling)->setTiknummer(102)->setNaam('Nijlen')->setVoornaam('Test')->setCode('unused')->setPost($nijlen);
        $verwijderd = (new Persoon())->setAfdeling($afdeling)->setTiknummer(103)->setNaam('Historiek')->setVoornaam('Test')->setCode('unused')->setPost($niel);
        $verwijderd->markeerVerwijderd();
        $nielVoertuig = (new Voertuig())->setAfdeling($afdeling)->setNaam('Pomp Niel')->setPost($niel);
        $nijlenVoertuig = (new Voertuig())->setAfdeling($afdeling)->setNaam('Pomp Nijlen')->setPost($nijlen);
        $lijst = (new Aanwezigheidslijst())->setAfdeling($afdeling)->setNummer(1)->setStart(new \DateTimeImmutable('-1 hour'))->setPost($niel);
        $tikking = (new Tikking())->setLijst($lijst)->setPersoon($nielPersoon)->setEinde(new \DateTimeImmutable())->setChauffeurVoertuig($nielVoertuig);
        $instelling = (new ChauffeurInstelling())->setAfdeling($afdeling)->setPost($niel)->setActief(false);
        foreach (compact('afdeling', 'niel', 'nijlen', 'boom', 'nielPersoon', 'nijlenPersoon', 'verwijderd', 'nielVoertuig', 'nijlenVoertuig', 'lijst', 'tikking', 'instelling') as $key => $entity) {
            $this->em->persist($entity);
            $entities[$key] = $entity;
        }
        $this->em->flush();
        foreach ($entities as $key => $entity) { $this->ids[$key] = $entity->getId(); }
    }

    private function migratie(): Version20261023160000
    {
        return new Version20261023160000($this->em->getConnection(), new NullLogger());
    }

    private function corrigeer(): void
    {
        $migration = $this->migratie();
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        $db = $this->em->getConnection();
        $db->transactional(static function () use ($migration, $db): void {
            foreach ($migration->getSql() as $query) { $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes()); }
        });
        $this->em->clear();
    }

    public function testBestaandePostenWordenGecorrigeerdMetBehoudVanRelatiesEnHistoriek(): void
    {
        $this->corrigeer();
        $niel = $this->em->find(Post::class, $this->ids['niel']);
        $nijlen = $this->em->find(Post::class, $this->ids['nijlen']);
        self::assertSame('NL', $niel->getCode());
        self::assertSame('NI', $nijlen->getCode());
        self::assertSame('Niel', $niel->getNaam());
        self::assertSame('Nijlen', $nijlen->getNaam());
        self::assertSame('2845', $niel->getPostcode());
        self::assertSame('2560', $nijlen->getPostcode());
        self::assertSame('BO', $this->em->find(Post::class, $this->ids['boom'])->getCode());
        foreach ([['nielPersoon', Persoon::class, $niel], ['nijlenPersoon', Persoon::class, $nijlen], ['nielVoertuig', Voertuig::class, $niel], ['nijlenVoertuig', Voertuig::class, $nijlen], ['lijst', Aanwezigheidslijst::class, $niel], ['instelling', ChauffeurInstelling::class, $niel]] as [$key, $class, $post]) {
            self::assertSame($post->getId(), $this->em->find($class, $this->ids[$key])->getPost()->getId());
        }
        $tikking = $this->em->find(Tikking::class, $this->ids['tikking']);
        self::assertSame($this->ids['nielPersoon'], $tikking->getPersoon()->getId());
        self::assertSame($this->ids['nielVoertuig'], $tikking->getChauffeurVoertuig()->getId());
        self::assertSame('Pomp Niel', $tikking->getChauffeurVoertuigNaam());
        $db = $this->em->getConnection();
        $deleted = $db->fetchAssociative('SELECT post_id, verwijderd_op FROM persoon WHERE id = ?', [$this->ids['verwijderd']]);
        self::assertSame($this->ids['niel'], (int) $deleted['post_id']);
        self::assertNotNull($deleted['verwijderd_op']);
        $logs = $db->fetchAllAssociative("SELECT * FROM handeling WHERE actie = 'post corrigeren' ORDER BY object_id");
        self::assertCount(2, $logs);
        foreach ($logs as $log) {
            self::assertSame(Post::class, $log['object_type']);
            self::assertSame('migration:Version20261023160000', $log['route']);
            $isNiel = (int) $log['object_id'] === $this->ids['niel'];
            self::assertSame($isNiel ? 'NI' : 'NL', json_decode($log['voor'], true)['code']);
            self::assertSame($isNiel ? 'NL' : 'NI', json_decode($log['na'], true)['code']);
        }
    }

    public function testCorrecteCodesEnHerhaaldUitvoerenWordenNietOpnieuwGewijzigd(): void
    {
        $this->corrigeer();
        $db = $this->em->getConnection();
        $voor = $db->fetchAllAssociative('SELECT * FROM post ORDER BY id');
        $migration = $this->migratie();
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        self::assertSame([], $migration->getSql());
        $this->corrigeer();
        self::assertSame($voor, $db->fetchAllAssociative('SELECT * FROM post ORDER BY id'));
        self::assertSame(2, (int) $db->fetchOne("SELECT COUNT(*) FROM handeling WHERE actie = 'post corrigeren'"));
    }

    public function testNieuweImportHerkentNielEnNijlenViaNaamEnAfkorting(): void
    {
        $this->corrigeer();
        $fixture = file_get_contents(dirname(__DIR__).'/fixtures/legacy-dump/boom.sql');
        foreach ([['Niel', 'Nijlen'], ['NL', 'NI'], ['nl', 'ni']] as $index => [$niel, $nijlen]) {
            $file = tempnam(sys_get_temp_dir(), 'postcode-import-');
            file_put_contents($file, str_replace(["'Boom'", "'Rumst'"], ["'".$niel."'", "'".$nijlen."'"], $fixture));
            try {
                $importer = new LegacyImporter($this->em);
                $importer->importeer($file, 'import-'.$index, 'Testimport', 1, [1000 => ['post' => $niel, 'peloton' => 1], 2000 => ['post' => $nijlen, 'peloton' => 5]], false, static function (string $message): void {});
            } finally { unlink($file); }
            $db = $this->em->getConnection();
            foreach ([42 => 'NL', 9001 => 'NL', 9002 => 'NI'] as $nummer => $code) {
                self::assertSame($code, $db->fetchOne('SELECT po.code FROM persoon p JOIN post po ON po.id = p.post_id JOIN afdeling a ON a.id = p.afdeling_id WHERE a.slug = ? AND p.tiknummer = ?', ['import-'.$index, $nummer]));
            }
            self::assertSame('NL', $db->fetchOne('SELECT po.code FROM aanwezigheidslijst l JOIN post po ON po.id = l.post_id JOIN afdeling a ON a.id = l.afdeling_id WHERE a.slug = ?', ['import-'.$index]));
        }
    }

    public function testEenOnbekendePostMetDezelfdeCodeWordtNietOverschreven(): void
    {
        $db = $this->em->getConnection();
        $db->update('post', ['naam' => 'Andere post'], ['id' => $this->ids['nijlen']]);
        $voor = $db->fetchAllAssociative('SELECT * FROM post ORDER BY id');
        $migration = $this->migratie();
        try {
            $migration->up(new \Doctrine\DBAL\Schema\Schema());
            self::fail('Een bestaande afkorting van een onbekende post werd overschreven.');
        } catch (\Doctrine\Migrations\Exception\AbortMigration $e) {
            self::assertStringContainsString('al in gebruik door een andere post', $e->getMessage());
        }
        self::assertSame([], $migration->getSql());
        self::assertSame($voor, $db->fetchAllAssociative('SELECT * FROM post ORDER BY id'));
    }
}
