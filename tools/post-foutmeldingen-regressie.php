<?php
// Isolated regression harness: production services and entities, dependency test doubles.
namespace Symfony\Component\Security\Core\User {
    interface UserInterface {}
    interface PasswordAuthenticatedUserInterface { public function getPassword(): ?string; }
}
namespace Doctrine\Common\Collections {
    interface Collection extends \IteratorAggregate {}
    class ArrayCollection implements Collection {
        private array $items=[];
        public function getIterator(): \Traversable { return new \ArrayIterator($this->items); }
        public function contains(object $item): bool { return in_array($item,$this->items,true); }
        public function add(object $item): void { $this->items[]=$item; }
        public function removeElement(object $item): void { $this->items=array_values(array_filter($this->items,fn($v)=>$v!==$item)); }
        public function toArray(): array { return $this->items; }
    }
}
namespace Doctrine\ORM {
    interface EntityManagerInterface { public function flush(): void; public function persist(object $entity): void; }
    class MemoryEntityManager implements EntityManagerInterface {
        public int $flushes=0;
        public array $persisted=[];
        public function flush(): void { ++$this->flushes; }
        public function persist(object $entity): void { $this->persisted[]=$entity; }
    }
}
namespace Symfony\Component\HttpFoundation\Session {
    interface SessionInterface { public function get(string $key,mixed $default=null): mixed; public function set(string $key,mixed $value): void; public function remove(string $key): mixed; }
    class MemorySession implements SessionInterface {
        public array $values=['_security_main'=>'POST-TOKEN'];
        public function get(string $key,mixed $default=null): mixed { return $this->values[$key]??$default; }
        public function set(string $key,mixed $value): void { $this->values[$key]=$value; }
        public function remove(string $key): mixed { $v=$this->get($key);unset($this->values[$key]);return $v; }
    }
}
namespace Symfony\Bundle\SecurityBundle {
    class Security {
        public function __construct(public ?\App\Entity\Persoon $user) {}
        public function getUser(): ?\App\Entity\Persoon { return $this->user; }
        public function logout(bool $validateCsrf=true): void { $this->user=null; }
    }
}
namespace Symfony\Component\EventDispatcher { interface EventSubscriberInterface {} }
namespace Symfony\Component\Routing\Generator {
    interface UrlGeneratorInterface { public function generate(string $name,array $parameters=[]): string; }
    class TestUrls implements UrlGeneratorInterface { public function generate(string $name,array $parameters=[]): string { return '/'.$name; } }
}
namespace Symfony\Component\HttpFoundation {
    class ParameterBag { public function __construct(private array $values) {} public function get(string $key): mixed { return $this->values[$key]??null; } }
    class Request {
        public ParameterBag $attributes;
        public function __construct(private \Symfony\Component\HttpFoundation\Session\SessionInterface $session,string $route) { $this->attributes=new ParameterBag(['_route'=>$route]); }
        public function getSession(): \Symfony\Component\HttpFoundation\Session\SessionInterface { return $this->session; }
    }
}
namespace Symfony\Component\HttpKernel { class KernelEvents { public const REQUEST='kernel.request'; } }
namespace Symfony\Component\HttpKernel\Event {
    class RequestEvent {
        public function __construct(private \Symfony\Component\HttpFoundation\Request $request,private bool $main=true) {}
        public function getRequest(): \Symfony\Component\HttpFoundation\Request { return $this->request; }
        public function isMainRequest(): bool { return $this->main; }
    }
}
namespace Symfony\Component\RateLimiter {
    interface RateLimiterFactoryInterface { public function create(?string $key=null): object; }
    class MemoryLimiterFactory implements RateLimiterFactoryInterface {
        public array $buckets=[];
        public function create(?string $key=null): object { return $this->buckets[$key]??=$this->newBucket(); }
        private function newBucket(): object { return new MemoryLimiter(); }
    }
    class MemoryLimiter {
        private int $remaining=5;
        public function consume(int $tokens=1): self { $this->remaining=max(0,$this->remaining-$tokens);return $this; }
        public function getRemainingTokens(): int { return $this->remaining; }
        public function reset(): void { $this->remaining=5; }
    }
}
namespace Symfony\Component\PasswordHasher\Hasher {
    use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
    interface UserPasswordHasherInterface {
        public function isPasswordValid(PasswordAuthenticatedUserInterface $user,string $plainPassword): bool;
        public function needsRehash(PasswordAuthenticatedUserInterface $user): bool;
        public function hashPassword(PasswordAuthenticatedUserInterface $user,string $plainPassword): string;
    }
    class TestHasher implements UserPasswordHasherInterface {
        public int $checks=0;
        public function isPasswordValid(PasswordAuthenticatedUserInterface $user,string $plainPassword): bool {
            ++$this->checks;
            $stored=$user->getPassword();
            return strlen($stored)===32?hash_equals($stored,md5($plainPassword)):password_verify($plainPassword,$stored);
        }
        public function needsRehash(PasswordAuthenticatedUserInterface $user): bool { return strlen($user->getPassword())===32; }
        public function hashPassword(PasswordAuthenticatedUserInterface $user,string $plainPassword): string { return password_hash($plainPassword,PASSWORD_BCRYPT,['cost'=>4]); }
    }
}
namespace App\Repository {
    class PersoonRepository {
        public array $people=[];
        public function find(int $id): ?\App\Entity\Persoon { return $this->people[$id]??null; }
        public function findOneInAfdeling(\App\Entity\Afdeling $afdeling,int $nr): ?\App\Entity\Persoon {
            foreach($this->people as $p)if($p->getAfdeling()->getId()===$afdeling->getId()&&$p->getTiknummer()===$nr)return $p;
            return null;
        }
    }
}
namespace App\Service {
    class FoutmeldingService { public array $meldingen=[]; public function nieuw(\App\Entity\Foutmelding $f): void { $this->meldingen[]=$f; } }
    class Mailer { public array $mails=[]; public function stuur($afdeling,$aan,$onderwerp,$template,$context=[]): int { $this->mails[]=compact('afdeling','aan','onderwerp','template','context');return count($aan); } }
}
namespace {
    define('POST_FOUT_PROJECT_DIR', dirname(__DIR__));
    require POST_FOUT_PROJECT_DIR.'/src/Enum/Rol.php';
    require POST_FOUT_PROJECT_DIR.'/src/Enum/FoutType.php';
    require POST_FOUT_PROJECT_DIR.'/src/Enum/FoutStatus.php';
    foreach(['Afdeling','Persoon','Aanwezigheidslijst','Tikking','Foutmelding','Voertuig'] as $entity)require POST_FOUT_PROJECT_DIR.'/src/Entity/'.$entity.'.php';
    require POST_FOUT_PROJECT_DIR.'/src/Service/TikFout.php';
    require POST_FOUT_PROJECT_DIR.'/src/Service/TikService.php';
    require POST_FOUT_PROJECT_DIR.'/src/Service/PostFoutmeldingToegang.php';
    require POST_FOUT_PROJECT_DIR.'/src/Service/FoutmeldingIndienenService.php';
    require POST_FOUT_PROJECT_DIR.'/src/Security/SessieTimeoutSubscriber.php';

    use App\Entity\Afdeling;
    use App\Entity\Persoon;
    use App\Enum\Rol;
    use App\Enum\FoutType;
    use App\Service\TikService;
    use App\Service\PostFoutmeldingToegang;
    use App\Service\FoutmeldingIndienenService;
    use App\Service\TikFout;
    use App\Repository\PersoonRepository;
    use Doctrine\ORM\MemoryEntityManager;
    use Symfony\Component\HttpFoundation\Session\MemorySession;
    use Symfony\Component\PasswordHasher\Hasher\TestHasher;
    use Symfony\Component\RateLimiter\MemoryLimiterFactory;

    function same(mixed $actual,mixed $expected,string $message=''): void { if($actual!==$expected)throw new \RuntimeException('Assertion failed: '.$message); }
    function throws(callable $action,string $class): void { try{$action();}catch(\Throwable $e){if($e instanceof $class)return;throw $e;}throw new \RuntimeException('Expected '.$class); }
    function id(object $o,int $id): object { (new \ReflectionProperty($o,'id'))->setValue($o,$id);return $o; }
    function setup(): array {
        $a=id((new Afdeling())->setNaam('Boom')->setSlug('boom'),1);
        $b=id((new Afdeling())->setNaam('Jeugd')->setSlug('jeugd'),2);
        $post=id((new Persoon())->setAfdeling($a)->setTiknummer(9000)->setRol(Rol::POST)->setSysteemaccount(true)->setNaam('Post')->setVoornaam('Boom'),10);
        $persoon=id((new Persoon())->setAfdeling($a)->setTiknummer(123)->setVoornaam('Test')->setNaam('Melder')->setCode(password_hash('4826',PASSWORD_BCRYPT,['cost'=>4])),20);
        $ander=id((new Persoon())->setAfdeling($b)->setTiknummer(123)->setCode(password_hash('7391',PASSWORD_BCRYPT,['cost'=>4])),30);
        $repo=new PersoonRepository();$repo->people=[10=>$post,20=>$persoon,30=>$ander];
        $em=new MemoryEntityManager();$hasher=new TestHasher();$limiter=new MemoryLimiterFactory();
        return ['a'=>$a,'b'=>$b,'post'=>$post,'persoon'=>$persoon,'ander'=>$ander,'repo'=>$repo,'em'=>$em,'hasher'=>$hasher,'tik'=>new TikService($repo,$hasher,$em,$limiter),'toegang'=>new PostFoutmeldingToegang($repo),'sessie'=>new MemorySession()];
    }
    $checks=[];
    $checks['persoonlijke code binnen juiste afdeling']=function(){extract(setup());same($tik->controleerVoorFoutmelding($a,'123','4826'),$persoon);};
    $checks['code uit andere afdeling wordt geweigerd']=function(){extract(setup());throws(fn()=>$tik->controleerVoorFoutmelding($a,'123','7391'),TikFout::class);};
    $checks['ongekend tiknummer wordt geweigerd']=function(){extract(setup());throws(fn()=>$tik->controleerVoorFoutmelding($a,'999','4826'),TikFout::class);};
    $checks['niet numeriek tiknummer wordt geweigerd']=function(){extract(setup());throws(fn()=>$tik->controleerVoorFoutmelding($a,'123x','4826'),TikFout::class);};
    $checks['lege code wordt geweigerd']=function(){extract(setup());throws(fn()=>$tik->controleerVoorFoutmelding($a,'123',''),TikFout::class);};
    $checks['inactief personeelslid wordt geweigerd']=function(){extract(setup());$persoon->setActief(false);throws(fn()=>$tik->controleerVoorFoutmelding($a,'123','4826'),TikFout::class);same($hasher->checks,0);};
    $checks['systeemaccount kan niet persoonlijk melden']=function(){extract(setup());$persoon->setSysteemaccount(true);throws(fn()=>$tik->controleerVoorFoutmelding($a,'123','4826'),TikFout::class);};
    $checks['postrol zonder systeemvlag wordt ook geweigerd']=function(){extract(setup());$persoon->setRol(Rol::POST);throws(fn()=>$tik->controleerVoorFoutmelding($a,'123','4826'),TikFout::class);};
    $checks['vijf foute codes blokkeren volgende controle']=function(){extract(setup());for($i=0;$i<5;++$i)throws(fn()=>$tik->controleerVoorFoutmelding($a,'123','0000'),TikFout::class);throws(fn()=>$tik->controleerVoorFoutmelding($a,'123','4826'),TikFout::class);same($hasher->checks,5);};
    $checks['oude code migreert na geldige controle']=function(){extract(setup());$persoon->setCode(md5('4826'));$tik->controleerVoorFoutmelding($a,'123','4826');same(password_verify('4826',$persoon->getCode()),true);};
    $checks['bestaand intikken kan persoon nog reactiveren']=function(){extract(setup());$persoon->setActief(false);$lijst=(new \App\Entity\Aanwezigheidslijst())->setAfdeling($a);$tik->tikIn($lijst,'123','4826');same($persoon->isActief(),true);};
    $checks['melding krijgt juiste persoonlijke sessiecontext']=function(){extract(setup());$toegang->aanmelden($sessie,$post,$persoon);same($toegang->melder($sessie,$post),$persoon);same($sessie->get('_security_main'),'POST-TOKEN');};
    $checks['ander postaccount kan context niet overnemen']=function(){extract(setup());$toegang->aanmelden($sessie,$post,$persoon);$post2=clone $post;id($post2,11);same($toegang->melder($sessie,$post2),null);};
    $checks['andere afdeling mag geen context openen']=function(){extract(setup());throws(fn()=>$toegang->aanmelden($sessie,$post,$ander),\LogicException::class);};
    $checks['context vervalt bij deactiveren van melder']=function(){extract(setup());$toegang->aanmelden($sessie,$post,$persoon);$persoon->setActief(false);same($toegang->melder($sessie,$post),null);};
    $checks['context vervalt bij codewijziging']=function(){extract(setup());$toegang->aanmelden($sessie,$post,$persoon);$persoon->setCode('gewijzigde-hash');same($toegang->melder($sessie,$post),null);};
    $checks['context vervalt bij verplaatsing naar andere afdeling']=function(){extract(setup());$toegang->aanmelden($sessie,$post,$persoon);$persoon->setAfdeling($b);same($toegang->melder($sessie,$post),null);};
    $checks['context vervalt als postaccount wordt gedeactiveerd']=function(){extract(setup());$toegang->aanmelden($sessie,$post,$persoon);$post->setActief(false);same($toegang->melder($sessie,$post),null);};
    $checks['context vervalt na vijftien minuten']=function(){extract(setup());$toegang->aanmelden($sessie,$post,$persoon);$data=$sessie->get('post_foutmelding_toegang');$data['tot']=time()-1;$sessie->set('post_foutmelding_toegang',$data);same($toegang->melder($sessie,$post),null);};
    $checks['ontbrekende of ongeldige context geeft geen toegang']=function(){extract(setup());same($toegang->melder($sessie,$post),null);$sessie->set('post_foutmelding_toegang','ongeldig');same($toegang->melder($sessie,$post),null);};
    $checks['afmelden wist persoonlijke context en behoudt posttoken']=function(){extract(setup());$toegang->aanmelden($sessie,$post,$persoon);$toegang->afmelden($sessie);same($toegang->melder($sessie,$post),null);same($sessie->get('_security_main'),'POST-TOKEN');};
    $checks['nieuwe fout wordt op juiste melder en afdeling geregistreerd']=function(){extract(setup());$fouten=new \App\Service\FoutmeldingService();$mailer=new \App\Service\Mailer();$s=new FoutmeldingIndienenService($fouten,$mailer,$em);$f=$s->indienen($persoon,['type'=>FoutType::ANDERS,'probleem'=>' Defect ','wat'=>' Lamp ']);same($f->getMelder(),$persoon);same($f->getAfdeling(),$a);same($f->getWat(),'Lamp');same($f->getProbleem(),'Defect');same(count($fouten->meldingen),1);};
    $checks['kledij vermeldt tiknummer van persoonlijke melder']=function(){extract(setup());$s=new FoutmeldingIndienenService(new \App\Service\FoutmeldingService(),new \App\Service\Mailer(),$em);$f=$s->indienen($persoon,['type'=>FoutType::KLEDIJ,'probleem'=>'Kapotte naad','wat'=>'','kledij'=>['Interventiejas']]);same($f->getWat(),'Kledij: 1 stuks - tiknummer 123');};
    $checks['voertuig uit andere afdeling wordt geweigerd']=function(){extract(setup());$v=(new \App\Entity\Voertuig())->setAfdeling($b)->setNaam('Andere wagen');$s=new FoutmeldingIndienenService(new \App\Service\FoutmeldingService(),new \App\Service\Mailer(),$em);throws(fn()=>$s->indienen($persoon,['type'=>FoutType::VOERTUIG,'probleem'=>'Defect','wat'=>'','voertuig'=>$v]),\LogicException::class);};
    $checks['kadaver behoudt bestaande mailafhandeling met persoonlijke melder']=function(){extract(setup());$a->setKadaverOntvangers('test@example.test');$mailer=new \App\Service\Mailer();$fouten=new \App\Service\FoutmeldingService();$s=new FoutmeldingIndienenService($fouten,$mailer,$em);same($s->indienen($persoon,['type'=>FoutType::KADAVER,'probleem'=>'Info','soortKadaver'=>'Kat','locatieKadaver'=>'Post','inOpdrachtVan'=>'Politie']),null);same(count($fouten->meldingen),0);same($mailer->mails[0]['context']['melder'],$persoon);};
    $checks['uitgeschakelde foutmeldingen worden geweigerd']=function(){extract(setup());$a->setFoutmeldingenActief(false);$s=new FoutmeldingIndienenService(new \App\Service\FoutmeldingService(),new \App\Service\Mailer(),$em);throws(fn()=>$s->indienen($persoon,['type'=>FoutType::ANDERS,'probleem'=>'Defect','wat'=>'Lamp']),\LogicException::class);};
    $checks['navigatie binnen postmelding behoudt persoonlijke toegang']=function(){extract(setup());$toegang->aanmelden($sessie,$post,$persoon);$security=new \Symfony\Bundle\SecurityBundle\Security($post);$s=new \App\Security\SessieTimeoutSubscriber($security,new \Symfony\Component\Routing\Generator\TestUrls(),$toegang);$s->onRequest(new \Symfony\Component\HttpKernel\Event\RequestEvent(new \Symfony\Component\HttpFoundation\Request($sessie,'app_fout_post_nieuw')));same($toegang->melder($sessie,$post),$persoon);same($security->getUser(),$post);};
    $checks['terug naar tik-pc wist persoonlijke toegang']=function(){extract(setup());$toegang->aanmelden($sessie,$post,$persoon);$security=new \Symfony\Bundle\SecurityBundle\Security($post);$s=new \App\Security\SessieTimeoutSubscriber($security,new \Symfony\Component\Routing\Generator\TestUrls(),$toegang);$s->onRequest(new \Symfony\Component\HttpKernel\Event\RequestEvent(new \Symfony\Component\HttpFoundation\Request($sessie,'app_tik_index')));same($toegang->melder($sessie,$post),null);same($security->getUser(),$post);};
    $checks['subrequest wist persoonlijke toegang niet']=function(){extract(setup());$toegang->aanmelden($sessie,$post,$persoon);$security=new \Symfony\Bundle\SecurityBundle\Security($post);$s=new \App\Security\SessieTimeoutSubscriber($security,new \Symfony\Component\Routing\Generator\TestUrls(),$toegang);$s->onRequest(new \Symfony\Component\HttpKernel\Event\RequestEvent(new \Symfony\Component\HttpFoundation\Request($sessie,'app_tik_index'),false));same($toegang->melder($sessie,$post),$persoon);};
    $checks['normale persoonlijke sessie blijft behouden']=function(){extract(setup());$security=new \Symfony\Bundle\SecurityBundle\Security($persoon);$s=new \App\Security\SessieTimeoutSubscriber($security,new \Symfony\Component\Routing\Generator\TestUrls(),$toegang);$s->onRequest(new \Symfony\Component\HttpKernel\Event\RequestEvent(new \Symfony\Component\HttpFoundation\Request($sessie,'app_fout_nieuw')));same($security->getUser(),$persoon);same($sessie->get('_security_main'),'POST-TOKEN');};
    $count=0;
    foreach($checks as $name=>$check){$check();++$count;echo 'OK '.$name.PHP_EOL;}
    echo $count.' geïsoleerde regressiescenario’s geslaagd.'.PHP_EOL;
}
