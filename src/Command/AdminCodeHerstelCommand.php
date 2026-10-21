<?php

namespace App\Command;

use App\Entity\Afdeling;
use App\Entity\Persoon;
use App\Enum\Rol;
use App\Repository\AfdelingRepository;
use App\Repository\PersoonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:admin:code-herstellen', description: 'Stelt een nieuwe code in voor een bestaand actief administratoraccount.')]
final class AdminCodeHerstelCommand extends Command
{
    public function __construct(
        private readonly AfdelingRepository $afdelingen,
        private readonly PersoonRepository $personen,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('afdeling', InputArgument::OPTIONAL, 'Slug van de afdeling; weglaten om een afdeling te kiezen.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('Voer dit commando interactief uit om de nieuwe code verborgen in te voeren.');

            return Command::INVALID;
        }

        $io->title('Administratorcode herstellen');
        $slug = $input->getArgument('afdeling');
        if (null !== $slug) {
            $afdeling = $this->afdelingen->findOneBy(['slug' => $slug]);
            if (!$afdeling instanceof Afdeling) {
                $io->error('De opgegeven afdeling bestaat niet. Laat het argument weg om een afdeling te kiezen.');

                return Command::INVALID;
            }
        } else {
            $afdelingen = $this->afdelingen->findAllGesorteerd();
            if (!$afdelingen) {
                $io->error('Er zijn geen afdelingen gevonden in de verbonden database.');

                return Command::FAILURE;
            }

            $keuzes = [];
            foreach ($afdelingen as $kandidaat) {
                $keuzes[] = sprintf('%s [%s]', $kandidaat->getNaam(), $kandidaat->getSlug());
            }
            $keuze = $io->choice('Kies de afdeling', $keuzes);
            $index = array_search($keuze, $keuzes, true);
            if (false === $index) {
                return Command::INVALID;
            }
            $afdeling = $afdelingen[$index];
        }

        $admins = $this->personen->findBy(
            ['afdeling' => $afdeling, 'rol' => Rol::ADMIN, 'actief' => true],
            ['tiknummer' => 'ASC'],
        );
        if (!$admins) {
            $io->error('Deze afdeling heeft geen actief administratoraccount. Er werd niets gewijzigd.');

            return Command::FAILURE;
        }

        $keuzes = [];
        foreach ($admins as $kandidaat) {
            $keuzes[] = sprintf('%d - %s', $kandidaat->getTiknummer(), $kandidaat->getVolledigeNaam());
        }
        $keuze = $io->choice('Kies het administratoraccount', $keuzes);
        $index = array_search($keuze, $keuzes, true);
        if (false === $index) {
            return Command::INVALID;
        }
        /** @var Persoon $admin */
        $admin = $admins[$index];

        $io->definitionList(
            ['Afdeling' => $afdeling->getNaam()],
            ['Tiknummer' => (string) $admin->getTiknummer()],
            ['Account' => $admin->getVolledigeNaam()],
        );

        $vraag = new Question('Nieuwe code (4 tot 12 cijfers)');
        $vraag->setHidden(true);
        $vraag->setHiddenFallback(false);
        $vraag->setMaxAttempts(3);
        $vraag->setValidator(static function (#[\SensitiveParameter] ?string $code): string {
            if (!preg_match('/\A[0-9]{4,12}\z/', (string) $code)) {
                throw new \RuntimeException('Gebruik 4 tot 12 cijfers.');
            }

            return $code;
        });
        $code = (string) $io->askQuestion($vraag);

        $herhaalVraag = new Question('Herhaal de nieuwe code');
        $herhaalVraag->setHidden(true);
        $herhaalVraag->setHiddenFallback(false);
        $herhaalVraag->setMaxAttempts(3);
        $herhaalVraag->setValidator(static function (#[\SensitiveParameter] ?string $herhaling) use ($code): string {
            if (!hash_equals($code, (string) $herhaling)) {
                throw new \RuntimeException('De codes komen niet overeen.');
            }

            return $herhaling;
        });
        $io->askQuestion($herhaalVraag);

        $admin->setCode($this->hasher->hashPassword($admin, $code));
        $this->em->flush();

        $io->success(sprintf(
            'Nieuwe code opgeslagen. Meld aan via /login/%s met tiknummer %d en de zojuist gekozen code.',
            $afdeling->getSlug(),
            $admin->getTiknummer(),
        ));

        return Command::SUCCESS;
    }
}
