<?php

namespace App\Command;

use App\Entity\Afdeling;
use App\Entity\Persoon;
use App\Enum\Rol;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:afdeling:nieuw', description: 'Maakt een lege afdeling aan met een administratoraccount.')]
final class AfdelingNieuwCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly UserPasswordHasherInterface $hasher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED)
            ->addArgument('naam', InputArgument::REQUIRED)
            ->addOption('admin-nummer', null, InputOption::VALUE_REQUIRED, 'Aanmeldnummer van de administrator', '9999');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = $io->askHidden('Code voor de administrator (minstens 4 cijfers)', function (?string $c) {
            if (!preg_match('/^\d{4,12}$/', (string) $c)) {
                throw new \RuntimeException('Minstens 4 cijfers.');
            }
            return $c;
        });
        $afdeling = (new Afdeling())->setSlug($input->getArgument('slug'))->setNaam($input->getArgument('naam'))->setTitel($input->getArgument('naam'));
        $admin = (new Persoon())->setAfdeling($afdeling)->setTiknummer((int) $input->getOption('admin-nummer'))
            ->setNaam('Administrator')->setVoornaam($afdeling->getNaam())->setRol(Rol::ADMIN)->setSysteemaccount(true);
        $admin->setCode($this->hasher->hashPassword($admin, $code));
        $this->em->persist($afdeling);
        $this->em->persist($admin);
        $this->em->flush();
        $io->success(sprintf('Afdeling "%s" aangemaakt. Meld aan via /login/%s met nummer %d.', $afdeling->getNaam(), $afdeling->getSlug(), $admin->getTiknummer()));
        return Command::SUCCESS;
    }
}
