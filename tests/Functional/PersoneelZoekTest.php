<?php

namespace App\Tests\Functional;

use App\Entity\{Afdeling, Persoon, Post, Rang};
use App\Enum\Rol;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Gebruik uitsluitend een lege, aparte testdatabase. */
final class PersoneelZoekTest extends WebTestCase
{
    private KernelBrowser $client;
    private array $ids = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $afdeling = (new Afdeling())->setSlug('boom')->setNaam('Boom/Rumst')->setTitel('Brandweer');
        $ander = (new Afdeling())->setSlug('ander')->setNaam('Andere afdeling')->setTitel('Andere afdeling');
        $boom = (new Post())->setNaam('Boom')->setCode('BO');
        $niel = (new Post())->setNaam('Niel')->setCode('NL');
        $majoor = (new Rang())->setNaam('Majoor')->setKort('Maj.')->setNiveau(50);
        $sergeant = (new Rang())->setNaam('Sergeant')->setKort('Sgt.')->setNiveau(20);
        $beheer = (new Persoon())->setAfdeling($afdeling)->setTiknummer(900)->setNaam('Beheer')->setVoornaam('Test')->setRol(Rol::PERSONEEL)->setSysteemaccount(true)->setCode('unused');
        $anderBeheer = (new Persoon())->setAfdeling($ander)->setTiknummer(900)->setNaam('Beheer')->setVoornaam('Ander')->setRol(Rol::PERSONEEL)->setSysteemaccount(true)->setCode('unused');
        $dave = (new Persoon())->setAfdeling($afdeling)->setTiknummer(101)->setZonenummer(81234)->setNaam('Smolderen')->setVoornaam('Dave')->setEmail('dave.test@example.org')->setPost($boom)->setRang($majoor)->setCode('unused');
        $jan = (new Persoon())->setAfdeling($afdeling)->setTiknummer(102)->setZonenummer(91235)->setNaam('De Smet')->setVoornaam('Jan')->setEmail('jan_naam@example.org')->setPost($niel)->setRang($sergeant)->setCode('unused');
        $an = (new Persoon())->setAfdeling($afdeling)->setTiknummer(103)->setZonenummer(84200)->setNaam("O'Brien")->setVoornaam('An')->setCode('unused');
        $inactief = (new Persoon())->setAfdeling($afdeling)->setTiknummer(104)->setNaam('Smolderen')->setVoornaam('Jef')->setPost($niel)->setActief(false)->setCode('unused');
        $vreemd = (new Persoon())->setAfdeling($ander)->setTiknummer(101)->setNaam('Smolderen')->setVoornaam('Dave elders')->setEmail('elders@example.org')->setPost($boom)->setCode('unused');
        $systeem = (new Persoon())->setAfdeling($afdeling)->setTiknummer(1000)->setNaam('Smolderen')->setVoornaam('Tik-pc')->setRol(Rol::POST)->setSysteemaccount(true)->setCode('unused');
        $verwijderd = (new Persoon())->setAfdeling($afdeling)->setTiknummer(105)->setNaam('Smolderen')->setVoornaam('Oud')->setCode('unused');
        $verwijderd->markeerVerwijderd();
        foreach (compact('afdeling', 'ander', 'boom', 'niel', 'majoor', 'sergeant', 'beheer', 'anderBeheer', 'dave', 'jan', 'an', 'inactief', 'vreemd', 'systeem', 'verwijderd') as $key => $entity) {
            $em->persist($entity);
            $entities[$key] = $entity;
        }
        $em->flush();
        foreach ($entities as $key => $entity) { $this->ids[$key] = $entity->getId(); }
        $this->client->loginUser($beheer);
    }

    private function nummers(Crawler $crawler): array
    {
        return $crawler->filter('tbody tr td.tabular:first-child')->each(static fn (Crawler $cell): int => (int) $cell->text());
    }

    public function testZoekenOpNamenNummersPostRangEnEmail(): void
    {
        foreach ([
            'smol' => [101], 'dave smolderen' => [101], 'SMOLDEREN Dave BO' => [101],
            '101' => [101], '812' => [101], 'Niel' => [102], 'NL' => [102],
            'jan_naam' => [102], "O'BRIEN" => [103], 'Maj.' => [101], 'sergeant' => [102],
            '' => [102, 103, 101], '   ' => [102, 103, 101],
        ] as $zoek => $verwacht) {
            $crawler = $this->client->request('GET', '/personeel', ['zoek' => $zoek]);
            self::assertResponseIsSuccessful();
            self::assertSame($verwacht, $this->nummers($crawler), $zoek);
            self::assertSame(trim($zoek), $crawler->filter('input[name="zoek"]')->attr('value'));
        }
    }

    public function testZoekwoordenZijnLetterlijkEnGevenEenDuidelijkLeegResultaat(): void
    {
        foreach (['%', '!', "' OR 1=1 --", 'onvindbaar', 'Smolderen Niel'] as $zoek) {
            $crawler = $this->client->request('GET', '/personeel', ['zoek' => $zoek]);
            self::assertResponseIsSuccessful();
            self::assertSame([], $this->nummers($crawler));
            self::assertSelectorTextContains('tbody', 'Geen personeelsleden gevonden voor deze zoekopdracht.');
        }
        $crawler = $this->client->request('GET', '/personeel', ['zoek' => '_']);
        self::assertSame([102], $this->nummers($crawler), 'Een underscore is een letterlijk teken, geen wildcard.');
    }

    public function testZoekenBehoudtSorteringEnActiefInactiefBijNavigeren(): void
    {
        $crawler = $this->client->request('GET', '/personeel', ['zoek' => 'Smolderen', 'sort' => 'tiknummer', 'richting' => 'DESC', 'inactief' => 1]);
        self::assertSame([104], $this->nummers($crawler));
        $form = $crawler->selectButton('Zoeken')->form();
        self::assertSame('1', $form['inactief']->getValue());
        self::assertSame('tiknummer', $form['sort']->getValue());
        self::assertSame('DESC', $form['richting']->getValue());
        $naamLink = $crawler->filter('thead a')->reduce(static fn (Crawler $link): bool => $link->text() === 'Naam')->link();
        parse_str(parse_url($naamLink->getUri(), PHP_URL_QUERY), $parameters);
        self::assertSame(['sort' => 'naam', 'richting' => 'ASC', 'inactief' => '1', 'zoek' => 'Smolderen'], $parameters);
        $crawler = $this->client->click($naamLink);
        self::assertSame([104], $this->nummers($crawler));
        $crawler = $this->client->click($crawler->selectLink('Actief personeel')->link());
        self::assertSame([101], $this->nummers($crawler));
        self::assertSelectorExists('input[name="zoek"][value="Smolderen"]');
        $crawler = $this->client->click($crawler->selectLink('Wissen')->link());
        self::assertSame([102, 103, 101], $this->nummers($crawler));
        self::assertSelectorExists('input[name="zoek"][value=""]');
        self::assertSelectorExists('input[name="sort"][value="naam"]');
        self::assertSelectorNotExists('input[name="inactief"]');
        $crawler = $this->client->request('GET', '/personeel', ['zoek' => '0']);
        parse_str(parse_url($crawler->selectLink('Inactief personeel')->link()->getUri(), PHP_URL_QUERY), $parameters);
        self::assertSame('0', $parameters['zoek']);
    }

    public function testZoekenBlijftBinnenAfdelingEnSluitSysteemaccountsEnVerwijderdePersonenUit(): void
    {
        $crawler = $this->client->request('GET', '/personeel', ['zoek' => 'Smolderen']);
        self::assertSame([101], $this->nummers($crawler));
        self::assertSelectorTextNotContains('tbody', 'elders');
        self::assertSelectorTextNotContains('tbody', 'Tik-pc');
        self::assertSelectorTextNotContains('tbody', 'Oud');
        $crawler = $this->client->request('GET', '/personeel', ['zoek' => 'Smolderen', 'inactief' => 1]);
        self::assertSame([104], $this->nummers($crawler));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($em->find(Persoon::class, $this->ids['anderBeheer']));
        $crawler = $this->client->request('GET', '/personeel', ['zoek' => 'Smolderen']);
        self::assertSame([101], $this->nummers($crawler));
        self::assertSelectorTextContains('tbody', 'Dave elders');
        self::assertSelectorTextNotContains('tbody', 'dave.test@example.org');
    }

    public function testHogereRolKanNietOpInactiefGezetWorden(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $baas = (new Persoon())->setAfdeling($em->find(Afdeling::class, $this->ids['afdeling']))->setTiknummer(106)->setNaam('Baas')->setVoornaam('Bea')->setCode('unused');
        $em->persist($baas);
        $em->flush();
        // Formulier ophalen zolang de rol nog lager is, daarna de rol verhogen en toch indienen.
        $crawler = $this->client->request('GET', '/personeel');
        $form = $crawler->filter(sprintf('form[action="/personeel/%d/actief"]', $baas->getId()))->form();
        $em->getConnection()->executeStatement('UPDATE persoon SET rol = ? WHERE id = ?', [Rol::BEHEER->value, $baas->getId()]);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue((bool) $em->getConnection()->fetchOne('SELECT actief FROM persoon WHERE id = ?', [$baas->getId()]));
        $crawler = $this->client->request('GET', '/personeel');
        self::assertCount(0, $crawler->filter(sprintf('form[action="/personeel/%d/actief"]', $baas->getId())));
        self::assertCount(1, $crawler->filter(sprintf('form[action="/personeel/%d/actief"]', $this->ids['dave'])));
    }

    public function testZoekfilterGeeftGeenPersoneelstoegangAanLidOfTikPc(): void
    {
        foreach (['dave', 'systeem'] as $key) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $this->client->loginUser($em->find(Persoon::class, $this->ids[$key]));
            $this->client->request('GET', '/personeel', ['zoek' => 'Smolderen']);
            self::assertResponseStatusCodeSame(403);
        }
    }
}
