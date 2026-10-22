<?php

namespace App\Command;

use App\Import\LegacyImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-legacy',
    description: 'Importeert een SQL-dump (of oude MySQL-database) als afdeling.',
    help: <<<'HELP'
        Voorbeelden:

          <info>php bin/console app:import-legacy boom-rumst /pad/naar/boom-rumst.sql --naam="Post Boom/Rumst" --tikpc=1000:Boom:1 --tikpc=2000:Rumst:5 --controleer</info>
          <info>php bin/console app:import-legacy boom-rumst /pad/naar/boom-rumst.sql --naam="Post Boom/Rumst" --tikpc=1000:Boom:1 --tikpc=2000:Rumst:5</info>
          <info>php bin/console app:import-legacy niel /pad/naar/niel.sql.gz --naam="Post Niel" --volgorde=2</info>

        Een SQL-dump wordt als gegevensbestand gelezen. CREATE DATABASE, USE en andere
        SQL uit de dump worden niet op de Symfony-database uitgevoerd. Er is geen extra
        database of SQLite-extensie nodig. DATABASE_URL blijft de Symfony-doeldatabase.

        --controleer leest en valideert de bron en controleert de doeltabellen zonder
        doelrecords te schrijven. --bron-database kiest een database in een meervoudige dump.
        --vervang verwijdert alleen de bestaande afdeling met deze slug en importeert ze
        opnieuw binnen dezelfde transactie. Maak vooraf een databaseback-up.

        --tikpc=LEVEL:POST[:PELOTON] koppelt het tik-pc-account met dat oude level aan een post,
        eventueel met een vast peloton (de oude code zette Boom altijd op peloton 1 en Rumst op 5).
        HELP,
)]
final class ImportLegacyCommand extends Command
{
    public function __construct(private readonly LegacyImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('afdeling', InputArgument::REQUIRED, 'Korte naam (slug) van de afdeling, bv. "boom-rumst"')
            ->addArgument('bron', InputArgument::REQUIRED, 'Pad naar .sql/.sql.gz, of een mysql://-verbinding met een bestaande oude database')
            ->addOption('naam', null, InputOption::VALUE_REQUIRED, 'Naam op de startpagina, bv. "Post Boom/Rumst"')
            ->addOption('volgorde', null, InputOption::VALUE_REQUIRED, 'Volgorde op de startpagina', '1')
            ->addOption('tikpc', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'LEVEL:POST[:PELOTON]')
            ->addOption('controleer', null, InputOption::VALUE_NONE, 'Controleert de dump en doeltabellen zonder doelrecords te wijzigen')
            ->addOption('bron-database', null, InputOption::VALUE_REQUIRED, 'Databasenaam uit USE bij een dump met meerdere databases')
            ->addOption('vervang', null, InputOption::VALUE_NONE, 'Bestaande afdeling met deze slug eerst verwijderen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = (string) $input->getArgument('afdeling');
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $io->error('De afdeling mag enkel kleine letters, cijfers en koppeltekens bevatten.');
            return Command::INVALID;
        }
        $tikPcs = [];
        foreach ($input->getOption('tikpc') as $optie) {
            $delen = explode(':', $optie);
            if (\count($delen) < 2 || \count($delen) > 3 || !ctype_digit($delen[0]) || '' === trim($delen[1]) || (isset($delen[2]) && (!ctype_digit($delen[2]) || (int) $delen[2] < 1 || (int) $delen[2] > 9))) {
                $io->error('Ongeldige --tikpc: '.$optie);
                return Command::INVALID;
            }
            $tikPcs[(int) $delen[0]] = ['post' => $delen[1], 'peloton' => isset($delen[2]) ? (int) $delen[2] : null];
        }

        // De oude databases bevatten honderdduizenden tikkingen.
        ini_set('memory_limit', '1G');
        $start = microtime(true);
        $tellers = $this->importer->importeer(
            (string) $input->getArgument('bron'),
            $slug,
            (string) ($input->getOption('naam') ?: $slug),
            (int) $input->getOption('volgorde'),
            $tikPcs,
            (bool) $input->getOption('vervang'),
            fn (string $regel) => $io->writeln($regel),
            (bool) $input->getOption('controleer'),
            $input->getOption('bron-database'),
        );

        $io->table(['Onderdeel', 'Aantal'], array_map(null, array_keys($tellers), array_values($tellers)));
        $io->success(sprintf('%s van "%s" klaar in %.1f s.', $input->getOption('controleer') ? 'Controle' : 'Import', $slug, microtime(true) - $start));
        return Command::SUCCESS;
    }
}
