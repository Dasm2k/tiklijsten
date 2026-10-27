<?php
namespace App\Command;

use App\Repository\FoutmeldingRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Vroeger gebeurde dit bij elke weergave van het overzicht; nu via cron, bv. elk uur. */
#[AsCommand(name: 'app:foutmeldingen:opruimen', description: 'Verplaatst afgewerkte en definitief buitendienst gezette foutmeldingen naar de prullenbak.')]
final class FoutmeldingenOpruimenCommand extends Command
{
    public function __construct(private readonly FoutmeldingRepository $fouten) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('dagen', null, InputOption::VALUE_REQUIRED, 'Minimum aantal dagen sinds het afsluiten', '1');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dagen = $input->getOption('dagen');
        if (!ctype_digit((string) $dagen)) { $io->error('Geef een geheel aantal dagen op.'); return Command::INVALID; }
        $aantal = $this->fouten->ruimOp(new \DateTimeImmutable(sprintf('-%d days', $dagen)));
        $io->success(sprintf('%d foutmelding(en) opgeruimd.', $aantal));
        return Command::SUCCESS;
    }
}
