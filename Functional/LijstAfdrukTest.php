<?php

namespace App\Tests\Functional;

use App\Entity\{Aanwezigheidslijst, Afdeling, Gemeente, InterventieType, Persoon, Post, Rang, Tikking, Voertuig};
use App\Enum\Rol;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class LijstAfdrukTest extends WebTestCase
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
        $vreemd = (new Afdeling())->setSlug('niel')->setNaam('Niel')->setTitel('Brandweer Niel');
        $boom = (new Post())->setNaam('Boom')->setCode('BO');
        $rumst = (new Post())->setNaam('Rumst')->setCode('RU');
        $rang = (new Rang())->setNaam('Sergeant')->setKort('Sgt.')->setNiveau(20);
        $gemeente = (new Gemeente())->setAfdeling($afdeling)->setNaam('Boom');
        $type = (new InterventieType())->setAfdeling($afdeling)->setNaam('Langdurige interventie');
        $admin = (new Persoon())->setAfdeling($afdeling)->setPost($boom)->setTiknummer(901)->setNaam('Beheer')->setVoornaam('Test')->setRol(Rol::ADMIN)->setCode('unused');
        $personeel = (new Persoon())->setAfdeling($afdeling)->setPost($boom)->setTiknummer(902)->setNaam('Personeel')->setVoornaam('Test')->setRol(Rol::PERSONEEL)->setCode('unused');
        $sergeant = (new Persoon())->setAfdeling($afdeling)->setPost($boom)->setTiknummer(903)->setNaam('Sergeant')->setVoornaam('Test')->setRang($rang)->setCode('unused');
        $lid = (new Persoon())->setAfdeling($afdeling)->setPost($boom)->setTiknummer(101)->setNaam('Voorbeeld')->setVoornaam('Jan')->setCode('unused');
        $pc = (new Persoon())->setAfdeling($afdeling)->setPost($boom)->setTiknummer(1000)->setNaam('Post')->setVoornaam('Boom')->setRol(Rol::POST)->setSysteemaccount(true)->setCode('unused');
        $voertuig = (new Voertuig())->setAfdeling($afdeling)->setPost($boom)->setNaam('Autopomp 1')->setRoepnaam('AP1 Boom');

        $normaalOpen = (new Aanwezigheidslijst())->setAfdeling($afdeling)->setPost($boom)->setNummer(1)->setStart(new \DateTimeImmutable('-4 hours'))->setGemeente($gemeente)->setInterventieType($type)->setBevelvoerder($admin);
        $normaalDicht = (new Aanwezigheidslijst())->setAfdeling($afdeling)->setPost($boom)->setNummer(2)->setStart(new \DateTimeImmutable('-4 hours'))->setAfgesloten(true)->setGemeente($gemeente)->setInterventieType($type)->setBevelvoerder($admin);
        $speciaalOpen = (new Aanwezigheidslijst())->setAfdeling($afdeling)->setPost($boom)->setNummer(3)->setStart(new \DateTimeImmutable('-3 hours'))->setSpeciaal(true)->setGemeente($gemeente)->setInterventieType($type)->setInfo('Open interventie');
        $speciaalDicht = (new Aanwezigheidslijst())->setAfdeling($afdeling)->setPost($boom)->setNummer(4)->setStart(new \DateTimeImmutable('-3 hours'))->setSpeciaal(true)->setAfgesloten(true)->setGemeente($gemeente)->setInterventieType($type)->setInfo('Afgewerkte interventie');
        $anderePost = (new Aanwezigheidslijst())->setAfdeling($afdeling)->setPost($rumst)->setNummer(5)->setStart(new \DateTimeImmutable('-3 hours'))->setSpeciaal(true)->setAfgesloten(true);
        $andereAfdeling = (new Aanwezigheidslijst())->setAfdeling($vreemd)->setPost($boom)->setNummer(1)->setStart(new \DateTimeImmutable('-3 hours'))->setSpeciaal(true)->setAfgesloten(true);
        $tikking = (new Tikking())->setPersoon($lid)->setStart(new \DateTimeImmutable('-2 hours'))->setEinde(new \DateTimeImmutable('-1 hour'))->setChauffeurVoertuig($voertuig);
        $speciaalDicht->addTikking($tikking);

        foreach (compact('afdeling', 'vreemd', 'boom', 'rumst', 'rang', 'gemeente', 'type', 'admin', 'personeel', 'sergeant', 'lid', 'pc', 'voertuig', 'normaalOpen', 'normaalDicht', 'speciaalOpen', 'speciaalDicht', 'anderePost', 'andereAfdeling', 'tikking') as $key => $entity) {
            $em->persist($entity);
            $entities[$key] = $entity;
        }
        $em->flush();
        foreach ($entities as $key => $entity) { $this->ids[$key] = $entity->getId(); }
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function login(string $persoon): void
    {
        $this->client->loginUser($this->em()->find(Persoon::class, $this->ids[$persoon]));
    }

    private function afdruk(string $lijst): string
    {
        return '/lijsten/'.$this->ids[$lijst].'/afdrukken';
    }

    public function testOpenLijstenZijnOokViaEenRechtstreekseUrlNietAfdrukbaar(): void
    {
        foreach (['admin', 'personeel', 'sergeant', 'pc'] as $persoon) {
            $this->login($persoon);
            foreach (['normaalOpen', 'speciaalOpen'] as $lijst) {
                $this->client->request('GET', $this->afdruk($lijst));
                self::assertResponseStatusCodeSame(403);
                self::assertSelectorNotExists('[data-auto-print]');
            }
        }
    }

    public function testAfgeslotenLijstenZijnAfdrukbaarVolgensRolEnRang(): void
    {
        foreach (['admin', 'personeel', 'pc', 'sergeant'] as $persoon) {
            $this->login($persoon);
            $this->client->request('GET', $this->afdruk('speciaalDicht'));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('tbody', 'Voorbeeld Jan');
            $this->client->request('GET', $this->afdruk('normaalDicht'));
            self::assertResponseStatusCodeSame('sergeant' === $persoon ? 403 : 200);
        }
        $this->login('lid');
        $this->client->request('GET', $this->afdruk('speciaalDicht'));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAfdrukkenBlijftAfgeschermdPerAfdelingEnPost(): void
    {
        $this->login('admin');
        $this->client->request('GET', $this->afdruk('andereAfdeling'));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->afdruk('anderePost'));
        self::assertResponseIsSuccessful();
        foreach (['pc', 'sergeant'] as $persoon) {
            $this->login($persoon);
            $this->client->request('GET', $this->afdruk('anderePost'));
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testExtraInformatieIsGewoneTekstInBeheerTikschermEnAfdruk(): void
    {
        $this->login('admin');
        $id = $this->ids['speciaalDicht'];
        foreach (['/lijsten/'.$id, '/lijsten/'.$id.'/bewerken', '/tikken/lijst/'.$id, $this->afdruk('speciaalDicht')] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.registratie-info', 'Periode 1');
            self::assertSelectorTextContains('.registratie-info', 'Chf: Autopomp 1');
            self::assertSelectorTextNotContains('.registratie-info', 'AP1 Boom');
            if (str_starts_with($url, '/tikken/')) {
                self::assertSelectorCount(1, '.aanwezigheid-periode');
                self::assertSelectorNotExists('.aanwezigheid-chauffeur .badge');
            } else {
                self::assertSelectorNotExists('.registratie-info .badge, .registratie-info .rounded-pill');
            }
            self::assertSelectorTextNotContains('main', 'Begonnen');
            self::assertSelectorTextNotContains('main', 'Beëindigd');
        }
        $tikking = $this->em()->find(Tikking::class, $this->ids['tikking']);
        $html = static::getContainer()->get(Environment::class)->render('lijst/_registratie_badges.html.twig', ['t' => $tikking]);
        $oudInclude = new Crawler($html);
        self::assertCount(0, $oudInclude->filter('.badge, .rounded-pill'));
        self::assertStringContainsString('Chf: Autopomp 1', $oudInclude->text());
    }

    public function testAfdruklinksVoorOpenLijstenZijnOveralVerborgen(): void
    {
        $this->login('admin');
        foreach (['/dashboard', '/lijsten', '/lijsten/'.$this->ids['normaalOpen'], '/lijsten/'.$this->ids['speciaalOpen'], '/tikken/lijst/'.$this->ids['speciaalOpen'], '/speciale-lijsten', '/speciale-lijsten/'.$this->ids['speciaalOpen']] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            foreach (['normaalOpen', 'speciaalOpen'] as $lijst) {
                self::assertSelectorNotExists('a[href="'.$this->afdruk($lijst).'"]');
            }
        }
    }

    public function testSpeciaalOverzichtHeeftAfdrukactieEnStatusfilters(): void
    {
        $this->login('admin');
        $this->client->request('GET', '/speciale-lijsten');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('thead', 'Interventie');
        self::assertSelectorTextContains('thead', 'Extra info');
        self::assertSelectorExists('a[href="'.$this->afdruk('speciaalDicht').'"]');
        self::assertSelectorNotExists('a[href="'.$this->afdruk('speciaalOpen').'"]');
        self::assertSelectorTextContains('tbody tr:first-child', 'Open interventie');
        $this->client->request('GET', '/speciale-lijsten?status=open');
        self::assertSelectorCount(1, 'tbody tr');
        self::assertSelectorTextContains('tbody', 'Open interventie');
        self::assertSelectorTextNotContains('tbody', 'Afgewerkte interventie');
        $this->client->request('GET', '/speciale-lijsten?status=afgesloten');
        self::assertSelectorCount(2, 'tbody tr');
        self::assertSelectorTextContains('tbody', 'Afgewerkte interventie');
        self::assertSelectorTextNotContains('tbody', 'Open interventie');
    }

    public function testSpeciaalOverzichtBeperktAfdrukactiesVolgensDeGebruiker(): void
    {
        $this->login('sergeant');
        $this->client->request('GET', '/speciale-lijsten');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, 'tbody tr');
        self::assertSelectorExists('a[href="'.$this->afdruk('speciaalDicht').'"]');
        self::assertSelectorNotExists('a[href="'.$this->afdruk('anderePost').'"]');
        $this->login('lid');
        $this->client->request('GET', '/speciale-lijsten');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href$="/afdrukken"]');
    }

    public function testHeropendeLijstVerliestAfdruktoegang(): void
    {
        $this->login('admin');
        $this->client->request('GET', $this->afdruk('speciaalDicht'));
        self::assertResponseIsSuccessful();
        $c = $this->client->request('GET', '/speciale-lijsten/'.$this->ids['speciaalDicht']);
        $this->client->submit($c->selectButton('Heropenen voor correcties')->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorNotExists('a[href="'.$this->afdruk('speciaalDicht').'"]');
        $this->client->request('GET', $this->afdruk('speciaalDicht'));
        self::assertResponseStatusCodeSame(403);
    }

    public function testTweedeBeginuurStaatAlleenInEenEigenVeldAlsHetIsIngevuld(): void
    {
        $this->login('admin');
        foreach (['normaalDicht', 'speciaalDicht'] as $key) {
            $this->client->request('GET', $this->afdruk($key));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextNotContains('.lijst-gegevens', '2de beginuur');

            $lijst = $this->em()->find(Aanwezigheidslijst::class, $this->ids[$key]);
            $begin = $lijst->getStart()->format('d/m/Y H:i');
            $tweede = $lijst->getStart()->modify('+30 minutes');
            $lijst->setTweedeStart($tweede);
            $this->em()->flush();
            $crawler = $this->client->request('GET', $this->afdruk($key));
            self::assertResponseIsSuccessful();
            foreach (['Begin' => $begin, '2de beginuur' => $tweede->format('d/m/Y H:i')] as $label => $waarde) {
                $veld = $crawler->filter('.lijst-gegevens > div')->reduce(static fn (Crawler $veld) => $label === $veld->filter('.lijst-gegeven-label')->text());
                self::assertCount(1, $veld);
                self::assertSame($waarde, $veld->filter('.lijst-gegeven-waarde')->text());
            }
        }
    }
}
