<?php
namespace App\Command;

use App\Entity\Persoon;
use App\Enum\Rol;
use App\Repository\AfdelingRepository;
use App\Repository\PersoonRepository;
use App\Security\Totp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:superadmin:instellen', description: 'Maakt Daves globale systeemaccount aan of herstelt het wachtwoord en 2FA.')]
final class SuperadminCommand extends Command
{
    public function __construct(private readonly AfdelingRepository $afdelingen, private readonly PersoonRepository $personen, private readonly EntityManagerInterface $em, private readonly UserPasswordHasherInterface $hasher, private readonly Totp $totp) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('afdeling', InputArgument::REQUIRED, 'Bestaande afdelingsslug als technische thuisafdeling.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) { $io->error('Voer interactief uit; wachtwoord wordt verborgen ingevoerd.'); return Command::INVALID; }
        $afdeling = $this->afdelingen->findOneBy(['slug' => $input->getArgument('afdeling')]);
        if (!$afdeling) { $io->error('Onbekende afdeling.'); return Command::INVALID; }
        $account = $this->personen->findOneBy(['email' => 'dave.smolderen@bwzr.be', 'rol' => Rol::SUPERADMIN]);
        if (!$account) {
            $nummer = 990000;
            while ($this->personen->findOneBy(['afdeling' => $afdeling, 'tiknummer' => $nummer])) { ++$nummer; }
            $account = (new Persoon())->setAfdeling($afdeling)->setTiknummer($nummer);
        }
        $vraag = new Question('Nieuw wachtwoord (minimaal 14 tekens, maximaal 72 bytes)');
        $vraag->setHidden(true); $vraag->setHiddenFallback(false); $vraag->setMaxAttempts(3);
        $vraag->setValidator(static function (#[\SensitiveParameter] ?string $value): string {
            if (mb_strlen((string) $value, 'UTF-8') < 14 || strlen($value) > 72) { throw new \RuntimeException('Gebruik minimaal 14 tekens en maximaal 72 bytes.'); }
            return $value;
        });
        $password = $io->askQuestion($vraag);
        $herhaal = new Question('Herhaal het wachtwoord'); $herhaal->setHidden(true); $herhaal->setHiddenFallback(false); $herhaal->setMaxAttempts(3);
        $herhaal->setValidator(static function (#[\SensitiveParameter] ?string $value) use ($password): string {
            if (!hash_equals($password, (string) $value)) { throw new \RuntimeException('Wachtwoorden verschillen.'); }
            return $value;
        });
        $io->askQuestion($herhaal);
        $secret = $this->totp->generateSecret();
        $io->text('Voeg in Microsoft Authenticator, Google Authenticator of een andere TOTP-app handmatig een tijdgebonden account toe.');
        $io->definitionList(['Account' => 'Tikkingen: dave.smolderen@bwzr.be'], ['Sleutel' => $secret], ['Instellingen' => 'Tijdgebonden, SHA-1, 6 cijfers, 30 seconden']);
        $vraag = new Question('Voer de 6-cijferige code uit je authenticator in');
        $vraag->setHidden(true); $vraag->setHiddenFallback(false); $vraag->setMaxAttempts(3);
        $vraag->setValidator(function (?string $code) use ($secret): int {
            $step = $this->totp->matchingStep($secret, trim((string) $code));
            if (null === $step) { throw new \RuntimeException('Ongeldige authenticatorcode.'); }
            return $step;
        });
        $step = $io->askQuestion($vraag);
        $account->setNaam('Smolderen')->setVoornaam('Dave')->setEmail('dave.smolderen@bwzr.be')
            ->setRol(Rol::SUPERADMIN)->setSysteemaccount(true)->setActief(true)->setSessieduur(15)
            ->setTotpSecret($secret)->setTotpLastStep($step)->setCode($this->hasher->hashPassword($account, $password));
        $this->em->persist($account); $this->em->flush();
        $io->success('Account ingesteld. Wacht op de volgende authenticatorcode en meld aan via /superadmin/login. Bewaar de sleutel veilig als herstelkopie. Bij verlies kun je dit commando opnieuw uitvoeren.');
        return Command::SUCCESS;
    }
}
