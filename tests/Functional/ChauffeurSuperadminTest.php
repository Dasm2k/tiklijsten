<?php
namespace App\Tests\Functional;

use App\Entity\{Aanwezigheidslijst, Afdeling, InterventieType, Persoon, Post, Tikking, Voertuig};
use App\Enum\Rol;
use App\Security\Totp;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Gebruik uitsluitend een lege, aparte testdatabase (zoals de bestaande TikFlowTest). */
final class ChauffeurSuperadminTest extends WebTestCase
{
    private $client;
    private array $ids = [];
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $meta = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta); $tool->createSchema($meta);
        static::getContainer()->get('cache.rate_limiter')->clear();
        $afd = (new Afdeling())->setSlug('boom-rumst')->setNaam('Boom/Rumst')->setTitel('Boom/Rumst')->setVoertuigenActief(true);
        $jeugd = (new Afdeling())->setSlug('jeugd')->setNaam('Jeugdbrandweer')->setTitel('Jeugdbrandweer')->setChauffeursActief(false);
        $boom = (new Post())->setCode('BO')->setNaam('Boom');
        $rumst = (new Post())->setCode('RU')->setNaam('Rumst');
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $pc = (new Persoon())->setAfdeling($afd)->setPost($boom)->setTiknummer(1000)->setNaam('Tik')->setVoornaam('Boom')->setRol(Rol::POST)->setSysteemaccount(true);
        $pc->setCode($hasher->hashPassword($pc, '1111'));
        $lid = (new Persoon())->setAfdeling($afd)->setPost($boom)->setTiknummer(101)->setNaam('Peeters')->setVoornaam('Jan')->setCode('unused');
        $admin = (new Persoon())->setAfdeling($afd)->setTiknummer(990000)->setNaam('Smolderen')->setVoornaam('Dave')->setEmail('dave.smolderen@bwzr.be')->setRol(Rol::SUPERADMIN)->setSysteemaccount(true)->setTotpSecret(self::SECRET)->setSessieduur(15);
        $admin->setCode($hasher->hashPassword($admin, 'Testwachtwoord-16tekens'));
        $inactief = (new Persoon())->setAfdeling($jeugd)->setTiknummer(1000)->setNaam('Jeugd')->setVoornaam('Tik-pc')->setCode($hasher->hashPassword($pc, '1111'))->setRol(Rol::POST)->setSysteemaccount(true)->setActief(false);
        $brand = (new InterventieType())->setAfdeling($afd)->setNaam('Brand');
        $oefening = (new InterventieType())->setAfdeling($afd)->setNaam('Oefening')->setChauffeurVerplicht(false);
        $jeugdType = (new InterventieType())->setAfdeling($jeugd)->setNaam('Jeugdbrandweer')->setChauffeurVerplicht(false);
        $lijst = (new Aanwezigheidslijst())->setAfdeling($afd)->setPost($boom)->setInterventieType($brand)->setNummer(1)->setStart(new \DateTimeImmutable('-1 hour'));
        $tik = (new Tikking())->setLijst($lijst)->setPersoon($lid)->setEinde(new \DateTimeImmutable());
        $eigen = (new Voertuig())->setAfdeling($afd)->setPost($boom)->setNaam('Autopomp Boom')->setRoepnaam('MFAP1');
        $ander = (new Voertuig())->setAfdeling($afd)->setPost($rumst)->setNaam('Autopomp Rumst');
        $inactiefVoertuig = (new Voertuig())->setAfdeling($afd)->setPost($boom)->setNaam('Oude autopomp')->setActief(false);
        $zonderPost = (new Voertuig())->setAfdeling($afd)->setNaam('Nog toe te wijzen');
        foreach (compact('afd','jeugd','boom','rumst','pc','lid','admin','inactief','brand','oefening','jeugdType','lijst','tik','eigen','ander','inactiefVoertuig','zonderPost') as $key=>$entity) { $em->persist($entity); $entities[$key]=$entity; }
        $em->flush();
        foreach ($entities as $key=>$entity) { $this->ids[$key]=$entity->getId(); }
    }

    private function pcLogin(): void
    {
        $c=$this->client->request('GET','/login/boom-rumst');
        $this->client->submit($c->selectButton('Aanmelden')->form(['tiknummer'=>'1000','code'=>'1111']));
        self::assertResponseRedirects('/dashboard');
    }

    private function chauffeurForm(): \Symfony\Component\DomCrawler\Form
    {
        $c=$this->client->request('GET','/tikken/lijst/'.$this->ids['lijst']);
        self::assertResponseIsSuccessful();
        return $c->filter('form[action$="/chauffeur"]')->form();
    }

    public function testChauffeurKeuzeHistoriekEnAfsluiten(): void
    {
        $this->pcLogin();
        $form=$this->chauffeurForm();
        $choices=$form['voertuig']->availableOptionValues();
        self::assertContains((string)$this->ids['eigen'],$choices);
        foreach (['ander','inactiefVoertuig','zonderPost'] as $key) { self::assertNotContains((string)$this->ids[$key],$choices); }
        $this->client->submit($form,['voertuig'=>(string)$this->ids['eigen']]);
        self::assertResponseRedirects();
        $em=static::getContainer()->get(EntityManagerInterface::class);
        $tik=$em->find(Tikking::class,$this->ids['tik']);
        self::assertStringContainsString('MFAP1',$tik->getChauffeurVermelding());
        $v=$em->find(Voertuig::class,$this->ids['eigen']);
        $v->setNaam('Nieuwe naam')->setActief(false); $em->flush();
        $this->chauffeurForm();
        self::assertSelectorTextContains('body','MFAP1 · Autopomp Boom');
        $em=static::getContainer()->get(EntityManagerInterface::class);
        $em->find(Aanwezigheidslijst::class,$this->ids['lijst'])->setAfgesloten(true);$em->flush();
        $this->client->request('GET','/lijsten/'.$this->ids['lijst'].'/afdrukken');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body','Chf: Autopomp Boom');
        self::assertSelectorTextNotContains('tbody','MFAP1');
        self::assertSelectorTextNotContains('tbody','Nieuwe naam');
        $this->client->submit($form,['voertuig'=>'']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testVerkeerdePostEnInactiefWordenServerzijdigGeweigerd(): void
    {
        $this->pcLogin();
        foreach (['ander','inactiefVoertuig','zonderPost'] as $key) {
            $form=$this->chauffeurForm();
            $data=$form->getPhpValues();$data['voertuig']=(string)$this->ids[$key];
            $this->client->request('POST',$form->getUri(),$data);
            self::assertResponseStatusCodeSame(403);
        }
        $form=$this->chauffeurForm();$data=$form->getPhpValues();$data['_token']='ongeldig';
        $this->client->request('POST',$form->getUri(),$data);self::assertResponseStatusCodeSame(403);
    }

    public function testAfsluitenZonderChauffeurIsGeblokkeerd(): void
    {
        $this->pcLogin();
        $c=$this->client->request('GET','/tikken/lijst/'.$this->ids['lijst']);
        self::assertSelectorExists('form[action$="/afsluiten"] button[disabled]');
        $form=$c->filter('form[action$="/afsluiten"]')->form();
        $this->client->request('POST',$form->getUri(),$form->getPhpValues());
        self::assertResponseRedirects('/tikken/lijst/'.$this->ids['lijst']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-warning','Duid eerst minstens één chauffeur aan');
        $em=static::getContainer()->get(EntityManagerInterface::class);
        self::assertFalse($em->find(Aanwezigheidslijst::class,$this->ids['lijst'])->isAfgesloten());
        $form=$this->chauffeurForm();$this->client->submit($form,['voertuig'=>(string)$this->ids['eigen']]);
        $c=$this->client->followRedirect();
        self::assertSelectorNotExists('form[action$="/afsluiten"] button[disabled]');
        $this->client->submit($c->filter('form[action$="/afsluiten"]')->form());
        self::assertResponseRedirects();
        $em=static::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($em->find(Aanwezigheidslijst::class,$this->ids['lijst'])->isAfgesloten());
    }

    public function testUitgeschakeldePostKanZonderChauffeurAfsluiten(): void
    {
        $this->pcLogin();
        $formulier=$this->chauffeurForm();
        $em=static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new \App\Entity\ChauffeurInstelling())->setAfdeling($em->find(Afdeling::class,$this->ids['afd']))->setPost($em->find(Post::class,$this->ids['boom']))->setActief(false));$em->flush();
        // Een eerder geopend formulier kan de uitgeschakelde module niet omzeilen.
        $this->client->submit($formulier,['voertuig'=>(string)$this->ids['eigen']]);self::assertResponseStatusCodeSame(403);
        $c=$this->client->request('GET','/tikken/lijst/'.$this->ids['lijst']);
        self::assertSelectorNotExists('form[action$="/chauffeur"]');
        self::assertSelectorNotExists('form[action$="/afsluiten"] button[disabled]');
        $this->client->submit($c->filter('form[action$="/afsluiten"]')->form());self::assertResponseRedirects();
        $em=static::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($em->find(Aanwezigheidslijst::class,$this->ids['lijst'])->isAfgesloten());
    }

    public function testTypeZonderVerplichtingLaatVrijwilligeChauffeurEnAfsluitenToe(): void
    {
        $em=static::getContainer()->get(EntityManagerInterface::class);
        $em->find(Aanwezigheidslijst::class,$this->ids['lijst'])->setInterventieType($em->find(InterventieType::class,$this->ids['oefening']));$em->flush();
        $this->pcLogin();
        $c=$this->client->request('GET','/tikken/lijst/'.$this->ids['lijst']);
        self::assertSelectorNotExists('form[action$="/afsluiten"] button[disabled]');
        self::assertSelectorTextNotContains('.row.g-4','Duid eerst minstens één chauffeur aan');
        $this->client->submit($c->filter('form[action$="/chauffeur"]')->form(['voertuig'=>(string)$this->ids['eigen']]));self::assertResponseRedirects();
        $this->client->followRedirect();self::assertSelectorTextContains('.registratie-info','Chf: Autopomp Boom');
        $this->client->submit($this->chauffeurForm(),['voertuig'=>'']);self::assertResponseRedirects();
        $c=$this->client->followRedirect();$this->client->submit($c->filter('form[action$="/afsluiten"]')->form());self::assertResponseRedirects();
        $em=static::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($em->find(Aanwezigheidslijst::class,$this->ids['lijst'])->isAfgesloten());
        self::assertNull($em->find(Tikking::class,$this->ids['tik'])->getChauffeurVoertuig());
        self::assertNull($em->find(Tikking::class,$this->ids['tik'])->getChauffeurVoertuigNaam());
    }

    public function testGewijzigdeVerplichtingGeldtOokVoorEenEerderGeopendAfsluitformulier(): void
    {
        $em=static::getContainer()->get(EntityManagerInterface::class);
        $em->find(Aanwezigheidslijst::class,$this->ids['lijst'])->setInterventieType($em->find(InterventieType::class,$this->ids['oefening']));$em->flush();
        $this->pcLogin();$c=$this->client->request('GET','/tikken/lijst/'.$this->ids['lijst']);
        self::assertSelectorNotExists('form[action$="/afsluiten"] button[disabled]');$form=$c->filter('form[action$="/afsluiten"]')->form();
        $em=static::getContainer()->get(EntityManagerInterface::class);
        $em->find(InterventieType::class,$this->ids['oefening'])->setChauffeurVerplicht(true);$em->flush();
        $this->client->submit($form);self::assertResponseRedirects('/tikken/lijst/'.$this->ids['lijst']);$this->client->followRedirect();
        self::assertSelectorTextContains('.alert-warning','Duid eerst minstens één chauffeur aan');
        self::assertFalse(static::getContainer()->get(EntityManagerInterface::class)->find(Aanwezigheidslijst::class,$this->ids['lijst'])->isAfgesloten());
    }

    public function testBeheerKanChauffeurverplichtingPerTypeOpslaanEnLoggen(): void
    {
        $em=static::getContainer()->get(EntityManagerInterface::class);$this->client->loginUser($em->find(Persoon::class,$this->ids['admin']));
        $c=$this->client->request('GET','/beheer/interventies/'.$this->ids['brand']);self::assertResponseIsSuccessful();
        $form=$c->selectButton('Opslaan')->form();$form['interventie_type[chauffeurVerplicht]']->untick();
        $this->client->submit($form);self::assertResponseRedirects('/beheer/interventies');$this->client->followRedirect();
        self::assertSelectorTextContains('thead','Chauffeur verplicht');
        $em=static::getContainer()->get(EntityManagerInterface::class);self::assertFalse($em->find(InterventieType::class,$this->ids['brand'])->isChauffeurVerplicht());
        $log=$em->getConnection()->fetchAssociative("SELECT actor_id, voor, na FROM handeling WHERE object_type = ? AND object_id = ? AND actie = 'wijzigen' ORDER BY id DESC",[InterventieType::class,$this->ids['brand']]);
        self::assertSame($this->ids['admin'],(int)$log['actor_id']);
        self::assertTrue(json_decode($log['voor'],true)['chauffeurVerplicht']);self::assertFalse(json_decode($log['na'],true)['chauffeurVerplicht']);
        $c=$this->client->request('GET','/beheer/interventies/'.$this->ids['brand']);$form=$c->selectButton('Opslaan')->form();$form['interventie_type[chauffeurVerplicht]']->tick();
        $this->client->submit($form);self::assertResponseRedirects('/beheer/interventies');
        self::assertTrue(static::getContainer()->get(EntityManagerInterface::class)->find(InterventieType::class,$this->ids['brand'])->isChauffeurVerplicht());
    }

    public function testSpecialeLijstZonderChauffeurverplichtingVereistNogAlleEinduren(): void
    {
        $em=static::getContainer()->get(EntityManagerInterface::class);
        $em->find(Aanwezigheidslijst::class,$this->ids['lijst'])->setSpeciaal(true)->setInterventieType($em->find(InterventieType::class,$this->ids['oefening']));
        $em->find(Tikking::class,$this->ids['tik'])->setEinde(null);$em->flush();
        $this->client->loginUser($em->find(Persoon::class,$this->ids['admin']));
        $c=$this->client->request('GET','/speciale-lijsten/'.$this->ids['lijst']);self::assertResponseIsSuccessful();
        $form=$c->filter('form[action$="/afsluiten"]')->form();$this->client->request('POST',$form->getUri(),$form->getPhpValues());self::assertResponseRedirects();
        $this->client->followRedirect();self::assertSelectorTextContains('.alert-warning','open beginregistraties');
        $em=static::getContainer()->get(EntityManagerInterface::class);self::assertFalse($em->find(Aanwezigheidslijst::class,$this->ids['lijst'])->isAfgesloten());
        $em->find(Tikking::class,$this->ids['tik'])->setEinde(new \DateTimeImmutable());$em->flush();
        $c=$this->client->request('GET','/speciale-lijsten/'.$this->ids['lijst']);$this->client->submit($c->filter('form[action$="/afsluiten"]')->form());self::assertResponseRedirects();
        self::assertTrue(static::getContainer()->get(EntityManagerInterface::class)->find(Aanwezigheidslijst::class,$this->ids['lijst'])->isAfgesloten());
    }

    public function testInterventieInstellingIsAfgeschermdPerAfdelingEnVoorPostAccounts(): void
    {
        $this->pcLogin();$this->client->request('POST','/beheer/interventies/'.$this->ids['brand'],['interventie_type'=>['chauffeurVerplicht'=>false]]);self::assertResponseStatusCodeSame(403);
        $em=static::getContainer()->get(EntityManagerInterface::class);$this->client->loginUser($em->find(Persoon::class,$this->ids['admin']));
        $this->client->request('GET','/beheer/interventies/'.$this->ids['jeugdType']);self::assertResponseStatusCodeSame(404);
    }

    public function testJeugdbrandweerVolgtEigenAfdelingsinstelling(): void
    {
        $em=static::getContainer()->get(EntityManagerInterface::class);
        $afd=$em->find(Afdeling::class,$this->ids['afd']);$jeugd=$em->find(Afdeling::class,$this->ids['jeugd']);$boom=$em->find(Post::class,$this->ids['boom']);
        $em->persist((new \App\Entity\ChauffeurInstelling())->setAfdeling($afd)->setPost($boom)->setActief(true));$em->flush();
        $config=static::getContainer()->get(\App\Service\ChauffeurConfiguratie::class);
        $lijst=(new Aanwezigheidslijst())->setAfdeling($jeugd)->setPost($boom)->setNummer(1)->setStart(new \DateTimeImmutable('-1 hour'));
        $em->persist($lijst);$em->flush();
        self::assertFalse($config->isActief($lijst),'Booms instelling geldt niet voor Jeugdbrandweer op dezelfde fysieke post.');
        static::getContainer()->get(\App\Service\LijstService::class)->sluitAf($lijst);
        self::assertTrue($lijst->isAfgesloten());
    }

    public function testBeheerKanStandaardEnAfwijkingPerPostOpslaan(): void
    {
        $this->superLogin((new Totp())->code(self::SECRET,intdiv(time(),30)));
        $c=$this->client->request('GET','/instellingen');self::assertResponseIsSuccessful();
        $form=$c->filter('form[name="afdeling"]')->form();$form['afdeling[chauffeursActief]']->untick();
        $this->client->submit($form);self::assertResponseRedirects('/instellingen');
        $c=$this->client->followRedirect();
        $form=$c->selectButton('Chauffeursinstellingen opslaan')->form();
        $form['posten['.$this->ids['boom'].']']->select('aan');
        $form['posten['.$this->ids['rumst'].']']->select('uit');
        $this->client->submit($form);self::assertResponseRedirects('/instellingen');
        $em=static::getContainer()->get(EntityManagerInterface::class);
        $afd=$em->find(Afdeling::class,$this->ids['afd']);self::assertFalse($afd->isChauffeursActief());
        $config=static::getContainer()->get(\App\Service\ChauffeurConfiguratie::class);
        self::assertTrue($config->isActief((new Aanwezigheidslijst())->setAfdeling($afd)->setPost($em->find(Post::class,$this->ids['boom']))));
        self::assertFalse($config->isActief((new Aanwezigheidslijst())->setAfdeling($afd)->setPost($em->find(Post::class,$this->ids['rumst']))));
        self::assertFalse($config->isActief((new Aanwezigheidslijst())->setAfdeling($afd)));
    }

    private function superLogin(string $code): void
    {
        $c=$this->client->request('GET','/superadmin/login');
        $this->client->submit($c->selectButton('Aanmelden')->form(['email'=>'dave.smolderen@bwzr.be','password'=>'Testwachtwoord-16tekens','totp'=>$code]));
    }

    public function testSuperadminVereist2faEnKanAfdelingWisselen(): void
    {
        $this->superLogin('geen-code');self::assertResponseRedirects('/superadmin/login');
        $code=(new Totp())->code(self::SECRET,intdiv(time(),30));
        $this->superLogin($code);self::assertResponseRedirects('/superadmin/afdelingen');
        $c=$this->client->followRedirect();
        $this->client->submit($c->selectButton('Afdeling openen')->form(['afdeling'=>(string)$this->ids['jeugd']]));
        $this->client->followRedirect();self::assertSelectorTextContains('.navbar-brand','Jeugdbrandweer');
        $c=$this->client->request('GET','/instellingen/account/'.$this->ids['inactief']);self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="systeemaccount[actief]"]');
        $form=$c->selectButton('Opslaan')->form();$form['systeemaccount[actief]']->tick();
        $this->client->submit($form);self::assertResponseRedirects('/instellingen');
        $em=static::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($em->find(Persoon::class,$this->ids['inactief'])->isActief());
        // Afdeling wisselen mag de technische thuisafdeling van Dave niet wijzigen.
        $em=static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame($this->ids['afd'],$em->find(Persoon::class,$this->ids['admin'])->getAfdeling()->getId());
        $this->superLogin($code);self::assertResponseRedirects('/superadmin/login'); // code replay
    }

    public function testSuperadminKanNietViaGewoneLoginBinnen(): void
    {
        $c=$this->client->request('GET','/login/boom-rumst');
        $this->client->submit($c->selectButton('Aanmelden')->form(['tiknummer'=>'990000','code'=>'Testwachtwoord-16tekens']));
        self::assertResponseRedirects('/login/boom-rumst');
        $this->client->request('GET','/superadmin/afdelingen');self::assertResponseRedirects();
    }

    public function testGewoneGebruikerKanGeenAfdelingWisselen(): void
    {
        $this->pcLogin();$this->client->request('GET','/superadmin/afdelingen');self::assertResponseStatusCodeSame(403);
    }
}
