<?php

namespace App\Tests\Functional;

use App\Entity\{Aanwezigheidslijst, Afdeling, Persoon, Post, Rang, Tikking, Voertuig};
use App\Enum\Rol;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;

/** Gebruik uitsluitend een lege, aparte testdatabase. */
final class ChauffeurPostenTest extends WebTestCase
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

        $afdeling = (new Afdeling())->setSlug('boom-rumst')->setNaam('Boom/Rumst')->setTitel('Boom/Rumst');
        $andereAfdeling = (new Afdeling())->setSlug('ander')->setNaam('Andere afdeling')->setTitel('Andere afdeling');
        $boom = (new Post())->setCode('BO')->setNaam('Boom');
        $rumst = (new Post())->setCode('RU')->setNaam('Rumst');
        $niel = (new Post())->setCode('NL')->setNaam('Niel');
        $rang = (new Rang())->setNaam('Brandweerman')->setKort('Bwm.')->setNiveau(10);
        $tom = (new Persoon())->setAfdeling($afdeling)->setPost($niel)->setRang($rang)->setTiknummer(101)->setNaam('De Jonghe')->setVoornaam('Tom')->setCode('unused');
        $beheer = (new Persoon())->setAfdeling($afdeling)->setTiknummer(900)->setNaam('Beheer')->setVoornaam('Test')->setRol(Rol::PERSONEEL)->setSysteemaccount(true)->setCode('unused');
        $pc = (new Persoon())->setAfdeling($afdeling)->setPost($boom)->setTiknummer(1000)->setNaam('Tik-pc')->setVoornaam('Boom')->setRol(Rol::POST)->setSysteemaccount(true)->setCode('unused');
        $anderPersoon = (new Persoon())->setAfdeling($andereAfdeling)->setPost($boom)->setTiknummer(101)->setNaam('Andere')->setVoornaam('Persoon')->setCode('unused');
        $lijst = (new Aanwezigheidslijst())->setAfdeling($afdeling)->setPost($boom)->setNummer(1)->setStart(new \DateTimeImmutable('-1 hour'));
        $tik = (new Tikking())->setLijst($lijst)->setPersoon($tom)->setEinde(new \DateTimeImmutable());
        $lijst->addTikking($tik);
        $boomVoertuig = (new Voertuig())->setAfdeling($afdeling)->setPost($boom)->setNaam('Autopomp Boom')->setRoepnaam('MFAP1');
        $rumstVoertuig = (new Voertuig())->setAfdeling($afdeling)->setPost($rumst)->setNaam('Autopomp Rumst');
        $nielVoertuig = (new Voertuig())->setAfdeling($afdeling)->setPost($niel)->setNaam('Autopomp Niel');
        $inactief = (new Voertuig())->setAfdeling($afdeling)->setPost($boom)->setNaam('Oud voertuig')->setActief(false);
        $zonderPost = (new Voertuig())->setAfdeling($afdeling)->setNaam('Nog geen post');
        $vreemd = (new Voertuig())->setAfdeling($andereAfdeling)->setPost($boom)->setNaam('Voertuig andere afdeling');
        $verwijderd = (new Voertuig())->setAfdeling($afdeling)->setPost($boom)->setNaam('Verwijderd voertuig');
        $verwijderd->markeerVerwijderd();
        foreach (compact('afdeling', 'andereAfdeling', 'boom', 'rumst', 'niel', 'rang', 'tom', 'beheer', 'pc', 'anderPersoon', 'lijst', 'tik', 'boomVoertuig', 'rumstVoertuig', 'nielVoertuig', 'inactief', 'zonderPost', 'vreemd', 'verwijderd') as $key => $entity) {
            $em->persist($entity);
            $entities[$key] = $entity;
        }
        $em->flush();
        foreach ($entities as $key => $entity) { $this->ids[$key] = $entity->getId(); }
    }

    private function loginAls(string $key): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($em->find(Persoon::class, $this->ids[$key]));
    }

    private function kiesPosten(array $keys): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tom = $em->find(Persoon::class, $this->ids['tom']);
        foreach ($tom->getChauffeurPosten()->toArray() as $post) { $tom->removeChauffeurPost($post); }
        foreach ($keys as $key) { $tom->addChauffeurPost($em->find(Post::class, $this->ids[$key])); }
        $em->flush();
    }

    private function chauffeurForm(): Form
    {
        $crawler = $this->client->request('GET', '/tikken/lijst/'.$this->ids['lijst']);
        self::assertResponseIsSuccessful();
        return $crawler->filter('form[action$="/chauffeur"]')->form();
    }

    public function testTomUitNielKanMetBoomAlsVoertuigpostBoomsVoertuigKiezen(): void
    {
        $this->kiesPosten(['boom']);
        $this->loginAls('pc');
        $form = $this->chauffeurForm();
        $keuzes = $form['voertuig']->availableOptionValues();
        self::assertContains((string) $this->ids['boomVoertuig'], $keuzes);
        foreach (['rumstVoertuig', 'nielVoertuig', 'inactief', 'zonderPost', 'vreemd', 'verwijderd'] as $key) {
            self::assertNotContains((string) $this->ids[$key], $keuzes);
        }
        self::assertSelectorTextContains('#chauffeur'.$this->ids['tik'], 'Post: Niel');
        self::assertSelectorTextContains('#chauffeur'.$this->ids['tik'], 'Toegelaten voertuigposten: Boom');
        $this->client->submit($form, ['voertuig' => (string) $this->ids['boomVoertuig']]);
        self::assertResponseRedirects();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame($this->ids['boomVoertuig'], $em->find(Tikking::class, $this->ids['tik'])->getChauffeurVoertuig()->getId());
        self::assertSame($this->ids['niel'], $em->find(Persoon::class, $this->ids['tom'])->getPost()->getId());
    }

    public function testGeenAparteKeuzeBehoudtVoertuigenVanDeEigenPost(): void
    {
        $this->loginAls('pc');
        $form = $this->chauffeurForm();
        self::assertContains((string) $this->ids['nielVoertuig'], $form['voertuig']->availableOptionValues());
        self::assertNotContains((string) $this->ids['boomVoertuig'], $form['voertuig']->availableOptionValues());
        $this->client->submit($form, ['voertuig' => (string) $this->ids['nielVoertuig']]);
        self::assertResponseRedirects();
    }

    public function testMeerderePostenEnEenPersoonZonderEigenPostWordenOndersteund(): void
    {
        $this->kiesPosten(['boom', 'rumst']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->find(Persoon::class, $this->ids['tom'])->setPost(null);
        $em->flush();
        $this->loginAls('pc');
        $form = $this->chauffeurForm();
        foreach (['boomVoertuig', 'rumstVoertuig'] as $key) {
            self::assertContains((string) $this->ids[$key], $form['voertuig']->availableOptionValues());
        }
        self::assertNotContains((string) $this->ids['nielVoertuig'], $form['voertuig']->availableOptionValues());
        $this->client->submit($form, ['voertuig' => (string) $this->ids['rumstVoertuig']]);
        self::assertResponseRedirects();
        $this->kiesPosten([]);
        $form = $this->chauffeurForm();
        self::assertSame(['', '__behouden'], $form['voertuig']->availableOptionValues());
        self::assertSelectorTextContains('#chauffeur'.$this->ids['tik'], 'Geen actieve voertuigen');
    }

    public function testAnderePostAfdelingEnOngeldigeVoertuigenWordenOokViaPostGeweigerd(): void
    {
        $this->kiesPosten(['boom']);
        $this->loginAls('pc');
        foreach (['rumstVoertuig', 'nielVoertuig', 'inactief', 'zonderPost', 'vreemd', 'verwijderd'] as $key) {
            $form = $this->chauffeurForm();
            $data = $form->getPhpValues();
            $data['voertuig'] = (string) $this->ids[$key];
            $this->client->request('POST', $form->getUri(), $data);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertNull(static::getContainer()->get(EntityManagerInterface::class)->find(Tikking::class, $this->ids['tik'])->getChauffeurVoertuig());
    }

    public function testIngetrokkenVoertuigpostBlokkeertEenEerderGeopendFormulier(): void
    {
        $this->kiesPosten(['boom']);
        $this->loginAls('pc');
        $oudFormulier = $this->chauffeurForm();
        $this->kiesPosten(['rumst']);
        $this->client->submit($oudFormulier, ['voertuig' => (string) $this->ids['boomVoertuig']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testBestaandeRegistratieBlijftBehoudenNaWijzigingVanDeVoertuigpost(): void
    {
        $this->kiesPosten(['boom']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->find(Tikking::class, $this->ids['tik'])->setChauffeurVoertuig($em->find(Voertuig::class, $this->ids['boomVoertuig']));
        $em->flush();
        $this->kiesPosten(['rumst']);
        $this->loginAls('pc');
        $form = $this->chauffeurForm();
        self::assertSame('__behouden', $form['voertuig']->getValue());
        self::assertNotContains((string) $this->ids['boomVoertuig'], $form['voertuig']->availableOptionValues());
        $this->client->submit($form);
        self::assertResponseRedirects();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('Autopomp Boom', $em->find(Tikking::class, $this->ids['tik'])->getChauffeurVoertuigNaam());
        $form = $this->chauffeurForm();
        $this->client->submit($form, ['voertuig' => (string) $this->ids['rumstVoertuig']]);
        self::assertResponseRedirects();
    }

    public function testPersoneelsbeheerKanPostenOpslaanTerugvindenEnWissen(): void
    {
        $this->loginAls('beheer');
        $url = '/personeel/'.$this->ids['tom'].'/bewerken';
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="persoon[chauffeurPosten][]"][value="'.$this->ids['boom'].'"]');
        $form = $crawler->selectButton('Opslaan')->form();
        $data = $form->getPhpValues();
        $data['persoon']['chauffeurPosten'] = [(string) $this->ids['boom'], (string) $this->ids['rumst']];
        $this->client->request('POST', $form->getUri(), $data);
        self::assertResponseRedirects('/personeel');
        $this->client->followRedirect();
        self::assertSelectorTextContains('tbody', 'Boom, Rumst');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(2, $em->find(Persoon::class, $this->ids['tom'])->getChauffeurPosten());
        $log = $em->getConnection()->fetchAssociative("SELECT actor_id, voor, na FROM handeling WHERE object_type = ? AND object_id = ? AND actie = 'relatie wijzigen' ORDER BY id DESC", [Persoon::class, $this->ids['tom']]);
        self::assertSame($this->ids['beheer'], (int) $log['actor_id']);
        self::assertCount(2, json_decode($log['na'], true)['leden']);

        $crawler = $this->client->request('GET', $url);
        self::assertSelectorExists('input[name="persoon[chauffeurPosten][]"][value="'.$this->ids['boom'].'"][checked]');
        self::assertSelectorExists('input[name="persoon[chauffeurPosten][]"][value="'.$this->ids['rumst'].'"][checked]');
        $form = $crawler->selectButton('Opslaan')->form();
        $data = $form->getPhpValues();
        unset($data['persoon']['chauffeurPosten']);
        $this->client->request('POST', $form->getUri(), $data);
        self::assertResponseRedirects('/personeel');
        $this->client->followRedirect();
        self::assertSelectorTextContains('tbody', 'Eigen post (Niel)');
        self::assertCount(0, static::getContainer()->get(EntityManagerInterface::class)->find(Persoon::class, $this->ids['tom'])->getChauffeurPosten());
    }

    public function testOngeldigePostEnOnbevoegdPersoneelsbeheerWordenGeweigerd(): void
    {
        $this->loginAls('beheer');
        $url = '/personeel/'.$this->ids['tom'].'/bewerken';
        $crawler = $this->client->request('GET', $url);
        $form = $crawler->selectButton('Opslaan')->form();
        $data = $form->getPhpValues();
        $data['persoon']['chauffeurPosten'] = ['999999'];
        $this->client->request('POST', $form->getUri(), $data);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, static::getContainer()->get(EntityManagerInterface::class)->find(Persoon::class, $this->ids['tom'])->getChauffeurPosten());
        $this->client->request('GET', '/personeel/'.$this->ids['anderPersoon'].'/bewerken');
        self::assertResponseStatusCodeSame(404);
        foreach (['pc', 'tom'] as $key) {
            $this->loginAls($key);
            $this->client->request('POST', $url, $data);
            self::assertResponseStatusCodeSame(403);
        }
    }
}
