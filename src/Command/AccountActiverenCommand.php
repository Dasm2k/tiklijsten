<?php
namespace App\Command;

use App\Entity\Persoon;
use App\Repository\AfdelingRepository;
use App\Repository\PersoonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:account:activeren', description: 'Inspecteert en activeert een geselecteerd account, ook als niemand meer kan aanmelden.')]
final class AccountActiverenCommand extends Command
{
    public function __construct(private readonly AfdelingRepository $afdelingen, private readonly PersoonRepository $personen, private readonly EntityManagerInterface $em) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('afdeling', InputArgument::REQUIRED, 'Afdelingsslug');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) { $io->error('Voer interactief uit om het juiste account te kiezen.'); return Command::INVALID; }
        $afdeling = $this->afdelingen->findOneBy(['slug' => $input->getArgument('afdeling')]);
        if (!$afdeling) { $io->error('Onbekende afdeling.'); return Command::INVALID; }
        $accounts = $this->personen->findBy(['afdeling' => $afdeling], ['tiknummer' => 'ASC']);
        if (!$accounts) { $io->error('Geen accounts.'); return Command::FAILURE; }
        $keuzes = array_map(fn (Persoon $p) => sprintf('%d · %s · %s · %s', $p->getTiknummer(), $p->getVolledigeNaam(), $p->getRol()->label(), $p->isActief() ? 'actief' : 'INACTIEF'), $accounts);
        $keuze = $io->choice('Welk account moet actief worden?', $keuzes);
        $account = $accounts[array_search($keuze, $keuzes, true)];
        $account->setActief(true); $this->em->flush();
        $io->success('Account actief gezet. De bestaande code is behouden.');
        return Command::SUCCESS;
    }
}
