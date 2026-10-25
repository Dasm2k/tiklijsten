<?php

namespace App\Tests\Functional;

use App\Entity\{Aanwezigheidslijst, Afdeling, Gemeente, InterventieType, Mailinglijst, Persoon, Post};
use App\Enum\Rol;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Gebruik uitsluitend een lege, aparte testdatabase. */
final class InterventienummerTest extends WebTestCase
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
        $post = (new Post())->setCode('BO')->setNaam('Boom');
        $gemeente = (new Gemeente())->setAfdeling($afdeling)->setNaam('Boom');
        $admin = (new Persoon())->setAfdeling($afdeling)->setPost($post)->setTiknummer(901)->setNaam('Beheer')->setVoornaam('Test')->setRol(Rol::ADMIN)->setEmail('beheer@example.org')->setCode('unused');
        $pc = (new Persoon())->setAfdeling($afdeling)->setPost($post)->setTiknummer(1000)->setNaam('Post')->setVoornaam('Boom')->setRol(Rol::POST)->setSysteemaccount(true)->setVastPeloton(1)->setCode('unused');
        $verplicht = (new InterventieType())->setAfdeling($afdeling)->setNaam('Brand')->setChauffeurVerplicht(false)->setInterventienummerVerplicht(true);
        $optioneel = (new InterventieType())->setAfdeling($afdeling)->setNaam('Oefening')->setChauffeurVerplicht(false);
        $vreemdType = (new InterventieType())->setAfdeling($vreemd)->setNaam('Ander type');
        $normaal = (new Aanwezigheidslijst())->setAfdeling($afdeling)->setPost($post)->setNummer(32937)->setVolgnummer(11789)->setStart(new \DateTimeImmutable('-2 hours'))->setGemeente($gemeente)->setBevelvoerder($admin)->setInterventieType($optioneel)->setInfo('Oefening');
        $speciaal = (new Aanwezigheidslijst())->setAfdeling($afdeling)->setPost($post)->setNummer(18)->setStart(new \DateTimeImmutable('-2 hours'))->setGemeente($gemeente)->setSpeciaal(true)->setInterventieType($optioneel)->setInfo('Langdurige interventie');
        $mailinglijst = (new Mailinglijst())->setAfdeling($afdeling)->setNaam('Wacht 1')->setPeloton(1)->addLid($admin);
        foreach (compact('afdeling', 'vreemd', 'post', 'gemeente', 'admin', 'pc', 'verplicht', 'optioneel', 'vreemdType', 'normaal', 'speciaal', 'mailinglijst') as $key => $entity) {
            $em->persist($entity);
            $entities[$key] = $entity;
        }
        $em->flush();
        foreach ($entities as $key => $entity) { $this->ids[$key] = $entity->getId(); }
    }

    private function em(): EntityManagerInterface { return static::getContainer()->get(EntityManagerInterface::class); }
    private function login(string $key): void { $this->client->loginUser($this->em()->find(Persoon::class, $this->ids[$key])); }

    private function nieuweLijst(string $type, string $nummer, bool $speciaal = false): void
    {
        $crawler = $this->client->request('GET', '/tikken');
        $data = $crawler->selectButton('Lijst maken')->form()->getPhpValues();
        $data['lijst'] = array_replace($data['lijst'], [
            'start' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i'),
            'gemeente' => (string) $this->ids['gemeente'],
            'interventieType' => (string) $this->ids[$type],
            'bevelvoerder' => $speciaal ? '' : (string) $this->ids['admin'],
            'interventienummer' => $nummer,
            'info' => 'Testinterventie',
        ]);
        if ($speciaal) { $data['lijst']['speciaal'] = '1'; }
        // Rechtstreeks posten controleert ook zonder HTML pattern/maxlength/required.
        $this->client->request('POST', '/tikken', $data);
    }

    public function testOptioneelVeldEnGrenswaardenWordenAlsIntegerBewaard(): void
    {
        $this->login('pc');
        $this->client->request('GET', '/tikken');
        self::assertSelectorExists('label[for="lijst_interventienummer"]');
        self::assertSelectorExists('input[name="lijst[interventienummer]"][placeholder="Interventienummer FrontForce"][maxlength="8"][inputmode="numeric"]');
        foreach (['' => null, '0' => 0, '1' => 1, '99999999' => 99999999, '00001234' => 1234] as $input => $expected) {
            $this->nieuweLijst('optioneel', (string) $input);
            self::assertResponseRedirects();
            $id = basename($this->client->getResponse()->headers->get('Location'));
            self::assertSame($expected, $this->em()->find(Aanwezigheidslijst::class, $id)->getInterventienummer());
        }
    }

    public function testOngeldigeNummersWordenZonderAfrondenGeweigerd(): void
    {
        $this->login('pc');
        foreach (['100000000', '-1', '+123', '1.5', '1,5', '1e3', '123A', '12 34', '000000001', '999999999999999999999999'] as $input) {
            $this->nieuweLijst('optioneel', $input);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('main', 'Vul een geheel getal van maximaal 8 cijfers in.');
            self::assertSame(2, $this->em()->getRepository(Aanwezigheidslijst::class)->count([]));
        }
    }

    public function testVerplichtingGeldtVoorGewoneEnSpecialeLijsten(): void
    {
        $this->login('pc');
        foreach ([false, true] as $speciaal) {
            $this->nieuweLijst('verplicht', '', $speciaal);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('main', 'Vul het interventienummer in voor dit interventietype.');
            $this->nieuweLijst('verplicht', '12345678', $speciaal);
            self::assertResponseRedirects();
            $id = basename($this->client->getResponse()->headers->get('Location'));
            $lijst = $this->em()->find(Aanwezigheidslijst::class, $id);
            self::assertSame(12345678, $lijst->getInterventienummer());
            self::assertSame($speciaal, $lijst->isSpeciaal());
            if ($speciaal) { self::assertNull($lijst->getBevelvoerder()); }
        }
    }

    public function testBewerkenWisselenVanTypeEnWissenWordenGevalideerdEnGelogd(): void
    {
        $this->login('admin');
        $url = '/lijsten/'.$this->ids['normaal'].'/bewerken';
        $crawler = $this->client->request('GET', $url);
        $data = $crawler->selectButton('Wijzigingen opslaan')->form()->getPhpValues();
        $data['lijst']['interventieType'] = (string) $this->ids['verplicht'];
        $data['lijst']['interventienummer'] = '';
        $this->client->request('POST', $url, $data);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Vul het interventienummer in voor dit interventietype.');
        $data['lijst']['interventienummer'] = '87654321';
        $this->client->request('POST', $url, $data);
        self::assertResponseRedirects($url);
        $this->client->request('GET', $url);
        self::assertSelectorExists('input[name="lijst[interventienummer]"][required][value="87654321"]');
        $data['lijst']['interventieType'] = (string) $this->ids['optioneel'];
        $data['lijst']['interventienummer'] = '';
        $this->client->request('POST', $url, $data);
        self::assertResponseRedirects($url);
        self::assertNull($this->em()->find(Aanwezigheidslijst::class, $this->ids['normaal'])->getInterventienummer());
        $log = $this->em()->getConnection()->fetchAssociative("SELECT actor_id, voor, na FROM handeling WHERE object_type = ? AND object_id = ? AND actie = 'wijzigen' ORDER BY id DESC", [Aanwezigheidslijst::class, $this->ids['normaal']]);
        self::assertSame($this->ids['admin'], (int) $log['actor_id']);
        self::assertSame(87654321, json_decode($log['voor'], true)['interventienummer']);
        self::assertNull(json_decode($log['na'], true)['interventienummer']);
    }

    public function testBeheerKanVerplichtingAanEnUitzettenBinnenEigenAfdeling(): void
    {
        $this->login('admin');
        $url = '/beheer/interventies/'.$this->ids['optioneel'];
        $crawler = $this->client->request('GET', $url);
        self::assertSelectorNotExists('input[name="interventie_type[interventienummerVerplicht]"][checked]');
        $form = $crawler->selectButton('Opslaan')->form();
        $form['interventie_type[interventienummerVerplicht]']->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/beheer/interventies');
        self::assertTrue($this->em()->find(InterventieType::class, $this->ids['optioneel'])->isInterventienummerVerplicht());
        $log = $this->em()->getConnection()->fetchAssociative("SELECT voor, na FROM handeling WHERE object_type = ? AND object_id = ? AND actie = 'wijzigen' ORDER BY id DESC", [InterventieType::class, $this->ids['optioneel']]);
        self::assertFalse(json_decode($log['voor'], true)['interventienummerVerplicht']);
        self::assertTrue(json_decode($log['na'], true)['interventienummerVerplicht']);
        $crawler = $this->client->request('GET', $url);
        $form = $crawler->selectButton('Opslaan')->form();
        $form['interventie_type[interventienummerVerplicht]']->untick();
        $this->client->submit($form);
        self::assertResponseRedirects('/beheer/interventies');
        self::assertFalse($this->em()->find(InterventieType::class, $this->ids['optioneel'])->isInterventienummerVerplicht());
        $this->client->request('GET', '/beheer/interventies/'.$this->ids['vreemdType']);
        self::assertResponseStatusCodeSame(404);
        $this->login('pc');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
    }

    public function testVerplichtingBlokkeertAlleAfsluitroutesVanBestaandeOpenLijsten(): void
    {
        $this->login('admin');
        $this->em()->find(InterventieType::class, $this->ids['optioneel'])->setInterventienummerVerplicht(true);
        $this->em()->flush();
        foreach (['normaal', 'speciaal'] as $key) {
            $id = $this->ids[$key];
            foreach (['open-sluiten', 'archiveren'] as $actie) {
                $crawler = $this->client->request('GET', '/lijsten');
                $form = $crawler->filter('form[action="/lijsten/'.$id.'/'.$actie.'"]')->form();
                $this->client->request('POST', $form->getUri(), $form->getPhpValues());
                self::assertResponseRedirects('/lijsten/'.$id);
                $this->client->followRedirect();
                self::assertSelectorTextContains('.alert-warning', 'verplichte interventienummer');
                self::assertFalse($this->em()->find(Aanwezigheidslijst::class, $id)->isAfgesloten());
                self::assertFalse($this->em()->find(Aanwezigheidslijst::class, $id)->isGearchiveerd());
            }
        }
        foreach (['normaal' => '/tikken/lijst/', 'speciaal' => '/speciale-lijsten/'] as $key => $prefix) {
            $id = $this->ids[$key];
            $crawler = $this->client->request('GET', $prefix.$id);
            $form = $crawler->filter('form[action$="/afsluiten"]')->form();
            $this->client->request('POST', $form->getUri(), $form->getPhpValues());
            self::assertResponseRedirects($prefix.$id);
            $this->client->followRedirect();
            self::assertSelectorTextContains('.alert-warning', 'verplichte interventienummer');
            self::assertFalse($this->em()->find(Aanwezigheidslijst::class, $id)->isAfgesloten());
        }
    }

    public function testNummerVerschijntOpLijstenAfdrukMailEnInZoekresultaten(): void
    {
        $this->login('admin');
        foreach (['normaal' => 12345678, 'speciaal' => 0] as $key => $nummer) {
            $this->em()->find(Aanwezigheidslijst::class, $this->ids[$key])->setInterventienummer($nummer);
        }
        $this->em()->flush();
        $id = $this->ids['normaal'];
        foreach (['/lijsten/'.$id, '/tikken/lijst/'.$id, '/lijsten?zoek=12345678', '/lijsten?zoek=%2311789'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', '12345678');
            self::assertSelectorTextContains('main', '#11789');
            self::assertSelectorTextNotContains('main', '32937');
        }
        foreach (['%2312345678', '%2332937'] as $zoek) {
            $this->client->request('GET', '/lijsten?zoek='.$zoek);
            self::assertSelectorTextContains('tbody', 'Geen lijsten gevonden');
        }
        foreach (['/speciale-lijsten', '/speciale-lijsten/'.$this->ids['speciaal'], '/lijsten?zoek=0'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Interventie #: 0');
        }
        $crawler = $this->client->request('GET', '/tikken/lijst/'.$id);
        $this->client->submit($crawler->filter('form[action$="/afsluiten"]')->form());
        self::assertResponseRedirects();
        self::assertEmailCount(1);
        self::assertEmailHtmlBodyContains(self::getMailerMessage(), 'Interventie #: 12345678');
        self::assertEmailHtmlBodyContains(self::getMailerMessage(), 'Aanwezigheidslijst #11789');
        self::assertEmailHtmlBodyNotContains(self::getMailerMessage(), '32937');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Interventie #');
        self::assertSelectorTextContains('main', '12345678');
        self::assertSelectorTextContains('h1', '#11789');
        self::assertSelectorTextNotContains('main', '32937');
    }
}
