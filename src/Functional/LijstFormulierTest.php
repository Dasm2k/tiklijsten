<?php

namespace App\Tests\Functional;

use App\Entity\{Aanwezigheidslijst, Afdeling, Gemeente, InterventieType, Persoon, Post};
use App\Enum\Rol;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Gebruik uitsluitend een lege, aparte testdatabase. */
final class LijstFormulierTest extends WebTestCase
{
    private KernelBrowser $client;
    private array $ids = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->em();
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $afdeling = (new Afdeling())->setSlug('boom')->setNaam('Boom')->setTitel('Brandweer Boom');
        $post = (new Post())->setCode('BO')->setNaam('Boom');
        $gemeente = (new Gemeente())->setAfdeling($afdeling)->setNaam('Boom');
        $type = (new InterventieType())->setAfdeling($afdeling)->setNaam('Administratie')->setChauffeurVerplicht(false);
        $admin = (new Persoon())->setAfdeling($afdeling)->setPost($post)->setTiknummer(901)->setNaam('Beheer')->setVoornaam('Test')->setRol(Rol::ADMIN)->setCode('unused');
        $pc = (new Persoon())->setAfdeling($afdeling)->setPost($post)->setTiknummer(1000)->setNaam('Post')->setVoornaam('Boom')->setRol(Rol::POST)->setSysteemaccount(true)->setVastPeloton(1)->setCode('unused');
        // Historische lijsten kunnen nog een leeg infoveld hebben.
        $oud = (new Aanwezigheidslijst())->setAfdeling($afdeling)->setPost($post)->setNummer(1)->setStart(new \DateTimeImmutable('-2 hours'))->setGemeente($gemeente)->setInterventieType($type)->setBevelvoerder($admin)->setAfgesloten(true);
        foreach (compact('afdeling', 'post', 'gemeente', 'type', 'admin', 'pc', 'oud') as $key => $entity) {
            $em->persist($entity);
            $entities[$key] = $entity;
        }
        $em->flush();
        foreach ($entities as $key => $entity) { $this->ids[$key] = $entity->getId(); }
    }

    private function em(): EntityManagerInterface { return static::getContainer()->get(EntityManagerInterface::class); }
    private function login(string $key): void { $this->client->loginUser($this->em()->find(Persoon::class, $this->ids[$key])); }

    public static function lijstSoorten(): iterable
    {
        yield 'gewone lijst' => [false];
        yield 'speciale lijst' => [true];
    }

    #[DataProvider('lijstSoorten')]
    public function testInfoIsOokZonderBrowservalidatieVerplicht(bool $speciaal): void
    {
        $this->login('pc');
        $crawler = $this->client->request('GET', '/tikken');
        self::assertSelectorExists('input[name="lijst[info]"][required][maxlength="100"]');
        $data = $crawler->selectButton('Lijst maken')->form()->getPhpValues();
        $data['lijst'] = array_replace($data['lijst'], [
            'start' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i'),
            'gemeente' => (string) $this->ids['gemeente'],
            'interventieType' => (string) $this->ids['type'],
            'bevelvoerder' => $speciaal ? '' : (string) $this->ids['admin'],
        ]);
        if ($speciaal) { $data['lijst']['speciaal'] = '1'; }
        foreach ([null, '', '   ', "\t\n"] as $info) {
            if (null === $info) { unset($data['lijst']['info']); }
            else { $data['lijst']['info'] = $info; }
            $this->client->request('POST', '/tikken', $data);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('main', 'Vul het infoveld in.');
            self::assertSame(1, $this->em()->getRepository(Aanwezigheidslijst::class)->count([]));
        }
        $data['lijst']['info'] = str_repeat('A', 101);
        $this->client->request('POST', '/tikken', $data);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->em()->getRepository(Aanwezigheidslijst::class)->count([]));

        foreach (['0', str_repeat('é', 100), '  Administratie in de kazerne  '] as $info) {
            $data['lijst']['info'] = $info;
            $this->client->request('POST', '/tikken', $data);
            self::assertResponseRedirects();
            $id = basename($this->client->getResponse()->headers->get('Location'));
            $lijst = $this->em()->find(Aanwezigheidslijst::class, $id);
            self::assertSame(trim($info), $lijst->getInfo());
            self::assertSame($speciaal, $lijst->isSpeciaal());
        }
    }

    public function testOudeLijstBlijftLeesbaarEnVereistInfoBijOpslaan(): void
    {
        $this->login('admin');
        $id = $this->ids['oud'];
        foreach (['/lijsten/'.$id, '/lijsten/'.$id.'/afdrukken'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextNotContains('main', 'Centralist');
        }
        $url = '/lijsten/'.$id.'/bewerken';
        $crawler = $this->client->request('GET', $url);
        self::assertSelectorExists('input[name="lijst[info]"][required]');
        self::assertSelectorNotExists('[name="lijst[centralist]"]');
        $data = $crawler->selectButton('Wijzigingen opslaan')->form()->getPhpValues();
        foreach (['', '   ', null] as $info) {
            if (null === $info) { unset($data['lijst']['info']); }
            else { $data['lijst']['info'] = $info; }
            $this->client->request('POST', $url, $data);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('main', 'Vul het infoveld in.');
            self::assertSame('', $this->em()->find(Aanwezigheidslijst::class, $id)->getInfo());
        }
        $data['lijst']['info'] = 'Administratie in de kazerne';
        $this->client->request('POST', $url, $data);
        self::assertResponseRedirects($url);
        self::assertSame($data['lijst']['info'], $this->em()->find(Aanwezigheidslijst::class, $id)->getInfo());
    }
}
