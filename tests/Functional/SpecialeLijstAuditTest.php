<?php
namespace App\Tests\Functional;

use App\Entity\{Aanwezigheidslijst,Afdeling,Handeling,Herinnering,Mailinglijst,Persoon,Post,Rang,Tikking};
use App\Enum\Rol;
use App\Service\{LijstService,SpecialeTikService,TikFout};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SpecialeLijstAuditTest extends WebTestCase
{
    private $client;
    private array $ids=[];
    protected function setUp(): void
    {
        $this->client=static::createClient();$em=$this->em();$schema=new SchemaTool($em);$meta=$em->getMetadataFactory()->getAllMetadata();$schema->dropSchema($meta);$schema->createSchema($meta);
        static::getContainer()->get('cache.rate_limiter')->clear();
        $afd=(new Afdeling())->setSlug('boom')->setNaam('Boom')->setTitel('Boom')->setChauffeursActief(false);
        $ander=(new Afdeling())->setSlug('niel')->setNaam('Niel')->setTitel('Niel');
        $post=(new Post())->setNaam('Boom')->setCode('BO');$rang=(new Rang())->setNaam('Sergeant')->setKort('Sgt.')->setNiveau(20);
        $pc=(new Persoon())->setAfdeling($afd)->setPost($post)->setTiknummer(1000)->setNaam('Post')->setVoornaam('Boom')->setSysteemaccount(true)->setRol(Rol::POST);
        $lid=(new Persoon())->setAfdeling($afd)->setPost($post)->setTiknummer(101)->setNaam('Lid')->setVoornaam('Jan');
        $sgt=(new Persoon())->setAfdeling($afd)->setPost($post)->setTiknummer(102)->setNaam('Sergeant')->setVoornaam('An')->setRang($rang);
        $beheer=(new Persoon())->setAfdeling($afd)->setTiknummer(103)->setNaam('Beheer')->setVoornaam('Dave')->setRol(Rol::BEHEER);
        $vreemd=(new Persoon())->setAfdeling($ander)->setTiknummer(101)->setNaam('Ander')->setVoornaam('Lid');
        $lijst=(new Aanwezigheidslijst())->setAfdeling($afd)->setPost($post)->setNummer(1)->setSpeciaal(true)->setStart(new \DateTimeImmutable('-5 hours'));
        $gewoon=(new Aanwezigheidslijst())->setAfdeling($afd)->setPost($post)->setNummer(2)->setStart(new \DateTimeImmutable('-5 hours'));
        $hasher=static::getContainer()->get('security.user_password_hasher');
        foreach ([$pc,$lid,$sgt,$beheer,$vreemd] as $user) { $user->setCode($hasher->hashPassword($user,'4321')); }
        foreach (compact('afd','ander','post','rang','pc','lid','sgt','beheer','vreemd','lijst','gewoon') as $key=>$entity) { $em->persist($entity);$entities[$key]=$entity; }
        $em->flush();foreach ($entities as $key=>$entity) { $this->ids[$key]=$entity->getId(); }
    }
    private function em(): EntityManagerInterface { return static::getContainer()->get(EntityManagerInterface::class); }
    private function list(): Aanwezigheidslijst { return $this->em()->find(Aanwezigheidslijst::class,$this->ids['lijst']); }
    private function person(string $key): Persoon { return $this->em()->find(Persoon::class,$this->ids[$key]); }
    private function service(): SpecialeTikService { return static::getContainer()->get(SpecialeTikService::class); }
    private function moment(string $value): string { return (new \DateTimeImmutable($value))->format('Y-m-d\TH:i'); }
    private function postTik(string $actie,string $moment): void
    {
        $c=$this->client->request('GET','/tikken/lijst/'.$this->ids['lijst']);self::assertResponseIsSuccessful();
        $this->client->submit($c->selectButton('Registreren')->form(['tiknummer'=>'101','code'=>'4321','moment'=>$this->moment($moment)]));self::assertResponseRedirects();
    }
    public static function officieren(): iterable
    {
        yield 'luitenant' => ['Luitenant', 'Lt.'];
        yield 'kapitein' => ['Kapitein', 'Kap.'];
        yield 'majoor' => ['Majoor', 'Maj.'];
    }
    public static function bevoegdeRangen(): iterable
    {
        yield 'sergeant' => ['Sergeant', 'Sgt.'];
        yield 'adjudant' => ['Adjudant', 'Adj.'];
        yield from self::officieren();
    }
    public function testSpecialeLijstZonderBevelvoerderKanAangemaakt(): void
    {
        $em=$this->em();$afd=$em->find(Afdeling::class,$this->ids['afd']);
        $gemeente=(new \App\Entity\Gemeente())->setAfdeling($afd)->setNaam('Boom');
        $type=(new \App\Entity\InterventieType())->setAfdeling($afd)->setNaam('Grote interventie');
        $em->persist($gemeente);$em->persist($type);$em->flush();
        $this->client->loginUser($this->person('pc'));$c=$this->client->request('GET','/tikken');
        $form=$c->selectButton('Lijst maken')->form([
            'lijst[speciaal]'=>'1','lijst[start]'=>$this->moment('-1 hour'),
            'lijst[peloton]'=>'1','lijst[gemeente]'=>(string)$gemeente->getId(),
            'lijst[interventieType]'=>(string)$type->getId(),'lijst[bevelvoerder]'=>'',
        ]);
        $this->client->submit($form);self::assertResponseRedirects();
        $lijst=$this->em()->getRepository(Aanwezigheidslijst::class)->findOneBy(['nummer'=>3]);
        self::assertNotNull($lijst);self::assertTrue($lijst->isSpeciaal());self::assertNull($lijst->getBevelvoerder());
        $this->client->followRedirect();self::assertSelectorNotExists('form[action$="/in"] [name="actie"]');self::assertSelectorNotExists('form[action$="/in"] [name="einde"]');
    }
    public function testGewoneLijstBlijftBevelvoerderVereisen(): void
    {
        $validator=static::getContainer()->get('validator');
        self::assertCount(1,$validator->validateProperty($this->em()->find(Aanwezigheidslijst::class,$this->ids['gewoon']),'bevelvoerder'));
        self::assertCount(0,$validator->validateProperty($this->list(),'bevelvoerder'));
    }
    public function testGewoneLijstZonderBevelvoerderWordtServerzijdigGeweigerd(): void
    {
        $em=$this->em();$afd=$em->find(Afdeling::class,$this->ids['afd']);
        $gemeente=(new \App\Entity\Gemeente())->setAfdeling($afd)->setNaam('Boom');
        $type=(new \App\Entity\InterventieType())->setAfdeling($afd)->setNaam('Interventie');
        $em->persist($gemeente);$em->persist($type);$em->flush();
        $this->client->loginUser($this->person('pc'));$c=$this->client->request('GET','/tikken');
        $this->client->submit($c->selectButton('Lijst maken')->form([
            'lijst[start]'=>$this->moment('-1 hour'),'lijst[peloton]'=>'1',
            'lijst[gemeente]'=>(string)$gemeente->getId(),'lijst[interventieType]'=>(string)$type->getId(),'lijst[bevelvoerder]'=>'',
        ]));
        self::assertResponseStatusCodeSame(422);self::assertSelectorTextContains('body','Kies een bevelvoerder.');
        self::assertSame(2,$this->em()->getRepository(Aanwezigheidslijst::class)->count([]));
    }

    public function testEindTikViaIntikWidgetWordtGeweigerd(): void
    {
        $this->client->loginUser($this->person('pc'));$this->postTik('begin','-2 hours');$c=$this->client->followRedirect();
        $form=$c->selectButton('Registreren')->form();$data=$form->getPhpValues();$data['actie']='einde';$data['moment']=$this->moment('-1 hour');$data['tiknummer']='101';$data['code']='4321';
        $this->client->request('POST',$form->getUri(),$data);$this->client->followRedirect();self::assertSelectorTextContains('.alert-danger','via Aanwezigen');
        $t=$this->em()->getRepository(Tikking::class)->findOneBy(['lijst'=>$this->ids['lijst']]);self::assertNull($t->getEinde());
    }

    public function testMeerderePeriodesEnHandmatigeCorrectie(): void
    {
        $service=$this->service();$lijst=$this->list();$lid=$this->person('lid');
        $eerste=$service->registreer($lijst,$lid,'begin',new \DateTimeImmutable('-4 hours'));
        self::assertNull($eerste->getEinde());self::assertSame(1,$lijst->getOpenRegistraties());
        $service->registreer($lijst,$lid,'einde',new \DateTimeImmutable('-3 hours'));
        $tweede=$service->registreer($lijst,$lid,'begin',new \DateTimeImmutable('-2 hours'));
        $service->registreer($lijst,$lid,'einde',new \DateTimeImmutable('-1 hour'));
        self::assertNotSame($eerste->getId(),$tweede->getId());self::assertSame(2,$tweede->getVolgnummer());
        $service->wijzig($eerste,$lid,new \DateTimeImmutable('-4 hours'),new \DateTimeImmutable('-150 minutes'));
        self::assertSame(5400,$eerste->getPrestatie());self::assertSame(0,$lijst->getOpenRegistraties());
        self::assertGreaterThan(0,$this->em()->getRepository(Handeling::class)->count(['objectType'=>Tikking::class,'objectId'=>$eerste->getId(),'actie'=>'wijzigen']));
        $this->client->loginUser($this->person('pc'));$this->client->request('GET','/tikken/lijst/'.$this->ids['lijst']);
        self::assertSelectorCount(2,'.aanwezigheid');self::assertSelectorCount(2,'.aanwezigheid-periode');
    }
    public function testDubbelBeginWordtGeweigerd(): void
    {
        $service=$this->service();$lijst=$this->list();$lid=$this->person('lid');$service->registreer($lijst,$lid,'begin',new \DateTimeImmutable('-2 hours'));
        $this->expectException(TikFout::class);$this->expectExceptionMessage('open registratie');$service->registreer($lijst,$lid,'begin',new \DateTimeImmutable('-1 hour'));
    }
    public function testOverlapWordtGeweigerd(): void
    {
        $service=$this->service();$lijst=$this->list();$lid=$this->person('lid');$t=$service->registreer($lijst,$lid,'begin',new \DateTimeImmutable('-4 hours'));$service->registreer($lijst,$lid,'einde',new \DateTimeImmutable('-3 hours'));
        $service->registreer($lijst,$lid,'begin',new \DateTimeImmutable('-2 hours'));
        $this->expectException(TikFout::class);$this->expectExceptionMessage('overlapt');$service->wijzig($t,$lid,new \DateTimeImmutable('-4 hours'),new \DateTimeImmutable('-1 hour'));
    }
    public function testAlleenEigenRegistratieAanpasbaar(): void
    {
        $t=$this->service()->registreer($this->list(),$this->person('sgt'),'begin',new \DateTimeImmutable('-2 hours'));
        $this->expectException(TikFout::class);$this->service()->wijzig($t,$this->person('lid'),new \DateTimeImmutable('-1 hour'),null);
    }
    public function testOnderofficierKanEinduurVanAnderPersoneelslidInvullenEnAanpassen(): void
    {
        $t=$this->service()->registreer($this->list(),$this->person('lid'),'begin',new \DateTimeImmutable('-3 hours'));
        $id=$t->getId();
        $this->client->loginUser($this->person('sgt'));$c=$this->client->request('GET','/speciale-lijsten/'.$this->ids['lijst']);
        self::assertResponseIsSuccessful();self::assertSelectorTextContains('body','Aanwezigen');
        $form=$c->filter('form[action$="/tikking/'.$id.'"]')->form(['einde'=>$this->moment('-1 hour')]);
        $this->client->submit($form);self::assertResponseRedirects();
        $em=$this->em();$entry=$em->find(Tikking::class,$id);self::assertNotNull($entry->getEinde());
        $row=$em->getConnection()->fetchAssociative("SELECT * FROM handeling WHERE object_type = ? AND object_id = ? AND actie = 'wijzigen' ORDER BY id DESC",[Tikking::class,$id]);
        self::assertSame($this->ids['sgt'],(int)$row['actor_id']);self::assertNull(json_decode($row['voor'],true)['einde']);
        $c=$this->client->followRedirect();$this->client->submit($c->filter('form[action$="/tikking/'.$id.'"]')->form(['einde'=>$this->moment('-30 minutes')]));
        self::assertResponseRedirects();self::assertSame($this->moment('-30 minutes'),$this->em()->find(Tikking::class,$id)->getEinde()->format('Y-m-d\\TH:i'));
    }

    public function testOpenPeriodeBlokkeertOokBevoegdeAfsluiter(): void
    {
        $this->service()->registreer($this->list(),$this->person('lid'),'begin',new \DateTimeImmutable('-2 hours'));
        $this->expectException(TikFout::class);$this->expectExceptionMessage('open beginregistraties');static::getContainer()->get(LijstService::class)->sluitAf($this->list(),$this->person('sgt'));
    }
    public function testGewoonLidKanSpecialeLijstNietSluiten(): void
    {
        $this->expectException(TikFout::class);$this->expectExceptionMessage('beheerder, sergeant, adjudant, luitenant, kapitein of majoor');static::getContainer()->get(LijstService::class)->sluitAf($this->list(),$this->person('lid'));
    }
    public function testSergeantEnBeheerderKunnenAfsluiten(): void
    {
        $rechten=static::getContainer()->get(\App\Service\SpecialeLijstRechten::class);
        self::assertTrue($rechten->magAfsluiten($this->list(),$this->person('sgt')));self::assertTrue($rechten->magAfsluiten($this->list(),$this->person('beheer')));self::assertFalse($rechten->magAfsluiten($this->list(),$this->person('pc')));
        static::getContainer()->get(LijstService::class)->sluitAf($this->list(),$this->person('sgt'));self::assertTrue($this->list()->isAfgesloten());
    }
    #[DataProvider('bevoegdeRangen')]
    public function testPostFlowPersoonlijkeAfsluiterEnAuditActor(string $naam, string $kort): void
    {
        $this->person('sgt')->getRang()->setNaam($naam)->setKort($kort);$this->em()->flush();
        self::assertSame(Rol::LID,$this->person('sgt')->getRol());
        $this->client->loginUser($this->person('pc'));$this->postTik('begin','-2 hours');$c=$this->client->followRedirect();
        self::assertSelectorTextContains('.registratie-tijden','Nog aanwezig');
        $form=$c->filter('form[action$="/tijden"]')->form(['einde'=>$this->moment('-1 hour'),'code'=>'4321']);
        $this->client->submit($form);$c=$this->client->followRedirect();self::assertSelectorExists('.registratie-tijden time[title="Einde '.(new \DateTimeImmutable('-1 hour'))->format('d/m/Y H:i').'"]');
        $form=$c->filter('form[action$="/afsluiten"]')->form(['afsluit_tiknummer'=>'102','afsluit_code'=>'4321']);$this->client->submit($form);self::assertResponseRedirects();
        $db=$this->em()->getConnection();$row=$db->fetchAssociative("SELECT * FROM handeling WHERE object_type = ? AND object_id = ? AND actie = 'afsluiten' ORDER BY id DESC",[Aanwezigheidslijst::class,$this->ids['lijst']]);
        self::assertSame($this->ids['sgt'],(int)$row['actor_id']);
        $tik=$db->fetchAssociative("SELECT * FROM handeling WHERE object_type = ? AND actie = 'aanmaken' ORDER BY id DESC",[Tikking::class]);self::assertSame($this->ids['lid'],(int)$tik['actor_id']);
        $all=implode('',$db->fetchFirstColumn('SELECT na FROM handeling'));self::assertStringNotContainsString('4321',$all);self::assertStringNotContainsString('$2y$',$all);
    }
    #[DataProvider('officieren')]
    public function testOfficierMetGewoneLidrolKanPersoneelstijdenWijzigenSluitenAfdrukkenEnHeropenen(string $naam, string $kort): void
    {
        $this->person('sgt')->getRang()->setNaam($naam)->setKort($kort);$this->em()->flush();
        self::assertSame(Rol::LID,$this->person('sgt')->getRol());
        $tikking=$this->service()->registreer($this->list(),$this->person('lid'),'begin',new \DateTimeImmutable('-3 hours'));
        $tikkingId=$tikking->getId();$url='/speciale-lijsten/'.$this->ids['lijst'];
        $this->client->loginUser($this->person('sgt'));$c=$this->client->request('GET',$url);self::assertResponseIsSuccessful();
        $form=$c->filter('form[action$="/tikking/'.$tikkingId.'"]')->form(['start'=>$this->moment('-2 hours'),'einde'=>$this->moment('-1 hour')]);
        $this->client->submit($form);self::assertResponseRedirects($url);
        $tikking=$this->em()->find(Tikking::class,$tikkingId);
        self::assertSame($this->moment('-2 hours'),$tikking->getStart()->format('Y-m-d\TH:i'));
        self::assertSame($this->moment('-1 hour'),$tikking->getEinde()->format('Y-m-d\TH:i'));
        $log=$this->em()->getConnection()->fetchAssociative("SELECT actor_id FROM handeling WHERE object_type = ? AND object_id = ? AND actie = 'wijzigen' ORDER BY id DESC",[Tikking::class,$tikkingId]);
        self::assertSame($this->ids['sgt'],(int)$log['actor_id']);
        $c=$this->client->followRedirect();$this->client->submit($c->filter('form[action$="/afsluiten"]')->form());self::assertResponseRedirects($url);
        self::assertTrue($this->list()->isAfgesloten());
        $log=$this->em()->getConnection()->fetchAssociative("SELECT actor_id FROM handeling WHERE object_type = ? AND object_id = ? AND actie = 'afsluiten' ORDER BY id DESC",[Aanwezigheidslijst::class,$this->ids['lijst']]);
        self::assertSame($this->ids['sgt'],(int)$log['actor_id']);
        $this->client->request('GET','/lijsten/'.$this->ids['lijst'].'/afdrukken');self::assertResponseIsSuccessful();self::assertSelectorTextContains('tbody','Lid Jan');
        $this->client->request('GET','/lijsten/'.$this->ids['gewoon']);self::assertResponseStatusCodeSame(403);
        $c=$this->client->request('GET',$url);$this->client->submit($c->selectButton('Heropenen voor correcties')->form());self::assertResponseRedirects($url);
        self::assertFalse($this->list()->isAfgesloten());
        $log=$this->em()->getConnection()->fetchAssociative("SELECT actor_id FROM handeling WHERE object_type = ? AND object_id = ? AND actie = 'heropenen' ORDER BY id DESC",[Aanwezigheidslijst::class,$this->ids['lijst']]);
        self::assertSame($this->ids['sgt'],(int)$log['actor_id']);
        $this->client->request('GET','/lijsten/'.$this->ids['lijst'].'/afdrukken');self::assertResponseStatusCodeSame(403);
    }
    public function testPersoonlijkSchermEnAnderAfdelingWordtGeweigerd(): void
    {
        $this->client->loginUser($this->person('lid'));$c=$this->client->request('GET','/speciale-lijsten/'.$this->ids['lijst']);self::assertResponseIsSuccessful();
        $this->client->submit($c->selectButton('Registreren')->form(['moment'=>$this->moment('-2 hours')]));self::assertResponseRedirects();
        $this->client->followRedirect();self::assertSelectorExists('form[action$="/tikking/1"]');
        $this->client->loginUser($this->person('vreemd'));$this->client->request('GET','/speciale-lijsten/'.$this->ids['lijst']);self::assertResponseStatusCodeSame(404);
    }
    public function testSoftDeleteBlijftFysiekBestaanEnKanHersteld(): void
    {
        $em=$this->em();$h=(new Herinnering())->setAfdeling($em->find(Afdeling::class,$this->ids['afd']))->setVraag('Controle');$em->persist($h);$em->flush();$id=$h->getId();$em->remove($h);$em->flush();$em->clear();
        self::assertNotFalse($em->getConnection()->fetchOne('SELECT verwijderd_op FROM herinnering WHERE id = ?',[$id]));self::assertNull($em->find(Herinnering::class,$id));
        self::assertSame(1,$em->getRepository(Handeling::class)->count(['objectType'=>Herinnering::class,'objectId'=>$id,'actie'=>'verwijderen']));
        $this->client->loginUser($this->person('beheer'));$c=$this->client->request('GET','/beheer/historiek/prullenbak');self::assertResponseIsSuccessful();
        $form=$c->filter('form[action$="/herinnering/'.$id.'"]')->form();$this->client->submit($form);self::assertResponseRedirects();
        $em=$this->em();self::assertNotNull($em->find(Herinnering::class,$id));self::assertSame(1,$em->getRepository(Handeling::class)->count(['objectType'=>Herinnering::class,'objectId'=>$id,'actie'=>'herstellen']));
        $this->client->request('GET','/beheer/historiek');self::assertResponseIsSuccessful();
    }
    public function testGewisteTikkingTeltNietMeeEnOpnieuwTikkenKan(): void
    {
        $em=$this->em();$lijst=$em->find(Aanwezigheidslijst::class,$this->ids['gewoon']);$lid=$this->person('lid');$service=static::getContainer()->get(\App\Service\TikService::class);
        $t=$service->tikIn($lijst,'101','4321');$id=$t->getId();$service->verwijder($lijst,$t,'4321');self::assertFalse($lijst->heeftGetikt($lid));
        $nieuw=$service->tikIn($lijst,'101','4321');self::assertNotSame($id,$nieuw->getId());self::assertCount(1,$lijst->getTikkingen());
        self::assertSame(2,(int)$em->getConnection()->fetchOne('SELECT COUNT(*) FROM tikking WHERE lijst_id = ?',[$lijst->getId()]));
    }
    public function testBevoegdeKanLijstHeropenenVoorCorrecties(): void
    {
        $this->list()->setAfgesloten(true);$this->em()->flush();
        $this->client->loginUser($this->person('sgt'));$c=$this->client->request('GET','/speciale-lijsten/'.$this->ids['lijst']);
        $this->client->submit($c->selectButton('Heropenen voor correcties')->form());self::assertResponseRedirects();
        self::assertFalse($this->list()->isAfgesloten());
        self::assertGreaterThan(0,$this->em()->getRepository(Handeling::class)->count(['objectType'=>Aanwezigheidslijst::class,'objectId'=>$this->ids['lijst'],'actie'=>'heropenen']));
    }

    public function testSoftDeleteVanMailinglijstBehoudtLedenEnLogtWijzigingen(): void
    {
        $em=$this->em();$lijst=(new Mailinglijst())->setAfdeling($em->find(Afdeling::class,$this->ids['afd']))->setNaam('Testlijst')->addLid($this->person('lid'));
        $em->persist($lijst);$em->flush();$id=$lijst->getId();$em->remove($lijst);$em->flush();
        self::assertSame(1,(int)$em->getConnection()->fetchOne('SELECT COUNT(*) FROM mailinglijst WHERE id = ?',[$id]));
        self::assertSame(1,(int)$em->getConnection()->fetchOne('SELECT COUNT(*) FROM mailinglijst_lid WHERE mailinglijst_id = ?',[$id]));
        self::assertSame(1,$em->getRepository(Handeling::class)->count(['objectType'=>Mailinglijst::class,'objectId'=>$id,'actie'=>'verwijderen']));
    }
    public function testChauffeurInstellingKanNaSoftDeleteOpnieuwGeactiveerd(): void
    {
        $this->client->loginUser($this->person('beheer'));
        foreach (['aan','standaard','uit','standaard','aan'] as $waarde) {
            $c=$this->client->request('GET','/instellingen');self::assertResponseIsSuccessful();
            $this->client->submit($c->selectButton('Chauffeursinstellingen opslaan')->form(['posten['.$this->ids['post'].']'=>$waarde]));
            self::assertResponseRedirects('/instellingen');
        }
        $em=$this->em();self::assertSame(1,(int)$em->getConnection()->fetchOne('SELECT COUNT(*) FROM chauffeur_instelling'));
        self::assertTrue(static::getContainer()->get(\App\Service\ChauffeurConfiguratie::class)->isActief($this->list()));
    }

    public function testGegevensEnAuditLogWordenSamenTeruggedraaid(): void
    {
        $em=$this->em();$db=$em->getConnection();$voor=(int)$db->fetchOne('SELECT COUNT(*) FROM handeling');
        $db->beginTransaction();$this->person('lid')->setNaam('Tijdelijke wijziging');$em->flush();
        self::assertGreaterThan($voor,(int)$db->fetchOne('SELECT COUNT(*) FROM handeling'));
        $db->rollBack();$em->clear();self::assertSame($voor,(int)$db->fetchOne('SELECT COUNT(*) FROM handeling'));self::assertSame('Lid',$this->person('lid')->getNaam());
    }

    public function testLogNietBeschikbaarVoorGewoonLid(): void
    {
        $this->client->loginUser($this->person('lid'));$this->client->request('GET','/beheer/historiek');self::assertResponseStatusCodeSame(403);$this->client->request('GET','/beheer/historiek/prullenbak');self::assertResponseStatusCodeSame(403);
    }
}
