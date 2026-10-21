<?php

namespace App\Tests\Functional;

use App\Entity\Aanwezigheidslijst;
use App\Entity\Afdeling;
use App\Entity\Gemeente;
use App\Entity\InterventieType;
use App\Entity\Mailinglijst;
use App\Entity\Persoon;
use App\Entity\Post;
use App\Entity\Rang;
use App\Enum\Rol;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Doorloopt de belangrijkste flow: aanmelden op de tik-pc, lijst maken, intikken
 * (met een oude md5-code) en afsluiten met e-mail. Vereist een MariaDB/MySQL-testdatabase
 * (DATABASE_URL in .env.test.local; het achtervoegsel "_test" wordt automatisch toegevoegd).
 */
final class TikFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get('cache.rate_limiter')->clear();
        $tool = new SchemaTool($this->em);
        $meta = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        $this->fixtures();
    }

    private function fixtures(): void
    {
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $boom = (new Post())->setCode('BO')->setNaam('Boom');
        $afd = (new Afdeling())->setSlug('boom-rumst')->setNaam('Post Boom/Rumst')->setTitel('Brandweer Boom');
        $niel = (new Afdeling())->setSlug('niel')->setNaam('Post Niel')->setTitel('Brandweer Niel');
        $rang = (new Rang())->setNiveau(15)->setKort('Kpl.')->setNaam('Korporaal');
        $gem = (new Gemeente())->setAfdeling($afd)->setNaam('Boom');
        $type = (new InterventieType())->setAfdeling($afd)->setNaam('Brand');

        $tikpc = (new Persoon())->setAfdeling($afd)->setTiknummer(2850)->setNaam('Boom')->setVoornaam('Centrale')
            ->setRol(Rol::POST)->setSysteemaccount(true)->setPost($boom)->setVastPeloton(1)->setSessieduur(0);
        $tikpc->setCode($hasher->hashPassword($tikpc, '1111'));
        $jan = (new Persoon())->setAfdeling($afd)->setTiknummer(101)->setNaam('Peeters')->setVoornaam('Jan')
            ->setRang($rang)->setPeloton(1)->setEmail('jan@example.org')->setCode(md5('4321'));
        $nielLid = (new Persoon())->setAfdeling($niel)->setTiknummer(101)->setNaam('Janssens')->setVoornaam('An')->setCode(md5('4321'));
        $wacht1 = (new Mailinglijst())->setAfdeling($afd)->setNaam('Wacht1')->setPeloton(1)->addLid($jan);

        foreach ([$boom, $afd, $niel, $rang, $gem, $type, $tikpc, $jan, $nielLid, $wacht1] as $e) {
            $this->em->persist($e);
        }
        $this->em->flush();
    }

    private function login(string $slug, string $nummer, string $code): void
    {
        $crawler = $this->client->request('GET', '/login/'.$slug);
        $form = $crawler->selectButton('Aanmelden')->form(['tiknummer' => $nummer, 'code' => $code]);
        $this->client->submit($form);
    }

    public function testVolledigeTikFlow(): void
    {
        $this->login('boom-rumst', '2850', '1111');
        self::assertResponseRedirects('/dashboard');

        // Nieuwe lijst maken
        $crawler = $this->client->request('GET', '/tikken');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Lijst maken')->form();
        $form['lijst[gemeente]']->select((string) $this->em->getRepository(Gemeente::class)->findOneBy([])->getId());
        $form['lijst[interventieType]']->select((string) $this->em->getRepository(InterventieType::class)->findOneBy([])->getId());
        $form['lijst[bevelvoerder]']->select((string) $this->em->getRepository(Persoon::class)->findOneBy(['tiknummer' => 101, 'afdeling' => 1])->getId());
        $form['lijst[start]'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i');
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'is aangemaakt');

        /** @var Aanwezigheidslijst $lijst */
        $lijst = $this->em->getRepository(Aanwezigheidslijst::class)->findOneBy([]);
        self::assertSame(1, $lijst->getPeloton(), 'Vast peloton van de tik-pc');
        self::assertSame('Boom', $lijst->getPost()->getNaam());

        // Intikken met een oude md5-code
        $form = $crawler->selectButton('Registreren')->form(['tiknummer' => '101', 'code' => '4321']);
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Peeters Jan is ingetikt');
        $this->em->clear();
        $jan = $this->em->getRepository(Persoon::class)->findOneBy(['tiknummer' => 101, 'afdeling' => 1]);
        self::assertStringStartsWith('$2y$', $jan->getCode(), 'md5-code is omgezet naar bcrypt');

        // Persoon van een andere afdeling met hetzelfde tiknummer telt niet mee
        $form = $crawler->selectButton('Registreren')->form(['tiknummer' => '101', 'code' => '4321']);
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'reeds geregistreerd');

        // Afsluiten: lijst gaat naar de mailinglijst van peloton 1
        $form = $crawler->filter('form[action$="/afsluiten"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertEmailHeaderSame($mail, 'Bcc', 'jan@example.org');
        self::assertEmailHtmlBodyContains($mail, 'Peeters Jan');

        $this->em->clear();
        self::assertTrue($this->em->getRepository(Aanwezigheidslijst::class)->find($lijst->getId())->isAfgesloten());
    }

    public function testFouteCodeEnRechten(): void
    {
        $this->login('boom-rumst', '101', '0000');
        self::assertResponseRedirects('/login/boom-rumst');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');

        $this->login('boom-rumst', '101', '4321');
        self::assertResponseRedirects('/dashboard');
        $this->client->request('GET', '/personeel');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/tikken');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/prestaties');
        self::assertResponseIsSuccessful();
    }

    public function testAfdelingenZijnGescheiden(): void
    {
        $this->login('niel', '101', '4321');
        self::assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.navbar-brand', 'Brandweer Niel');
        self::assertSelectorTextNotContains('main', 'Peeters');
    }

    public function testTeVeelFouteCodesWordtGeblokkeerd(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->login('boom-rumst', '101', '0000');
        }
        $this->login('boom-rumst', '101', '4321');
        self::assertResponseRedirects('/login/boom-rumst', null, 'Na 5 foute pogingen is ook de juiste code geblokkeerd');
    }

    public function testTeVeelFouteCodesBijIntikken(): void
    {
        $tik = static::getContainer()->get(\App\Service\TikService::class);
        $lijst = (new Aanwezigheidslijst())->setAfdeling($this->em->getRepository(Afdeling::class)->findOneBy(['slug' => 'boom-rumst']))
            ->setStart(new \DateTimeImmutable('-1 hour'))->setNummer(1)->setPeloton(1);
        $this->em->persist($lijst);
        $this->em->flush();
        for ($i = 0; $i < 5; ++$i) {
            try {
                $tik->tikIn($lijst, '101', '0000');
            } catch (\App\Service\TikFout) {
            }
        }
        $this->expectExceptionMessage('Te veel foute codes');
        $tik->tikIn($lijst, '101', '4321');
    }
}
