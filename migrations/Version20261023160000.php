<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\Post;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Corrigeert de oorspronkelijke codes met behoud van alle bestaande post-ID's. */
final class Version20261023160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Herstel postafkortingen: NL = Niel, NI = Nijlen; behoud personeel, voertuigen, instellingen en historiek.';
    }

    public function up(Schema $schema): void
    {
        $doelen = ['niel' => 'NL', 'nijlen' => 'NI'];
        $posten = [];
        foreach ($this->connection->fetchAllAssociative("SELECT id, code, naam FROM post WHERE LOWER(TRIM(naam)) IN ('niel', 'nijlen')") as $post) {
            $naam = strtolower(trim($post['naam']));
            $this->abortIf(isset($posten[$naam]), 'Meerdere posten met de naam '.$post['naam'].'. De afkortingen kunnen niet eenduidig worden hersteld.');
            $posten[$naam] = $post;
        }

        $codes = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, code FROM post') as $post) {
            $codes[strtoupper($post['code'])] = (int) $post['id'];
        }
        $ids = array_map(static fn (array $post): int => (int) $post['id'], array_values($posten));
        $wijzigingen = [];
        foreach ($posten as $naam => $post) {
            $code = $doelen[$naam];
            if ($post['code'] === $code) { continue; }
            $this->abortIf(isset($codes[$code]) && !in_array($codes[$code], $ids, true), 'Postafkorting '.$code.' is al in gebruik door een andere post. Er zijn geen postgegevens gewijzigd.');
            $wijzigingen[] = [$post, $code];
        }

        // Eerst beide unieke codes vrijmaken; personen en andere relaties blijven op hun post-ID.
        foreach ($wijzigingen as [$post]) {
            do { $tijdelijk = strtoupper(bin2hex(random_bytes(8))); } while (isset($codes[$tijdelijk]));
            $codes[$tijdelijk] = (int) $post['id'];
            $this->addSql('UPDATE post SET code = ? WHERE id = ?', [$tijdelijk, $post['id']]);
        }
        foreach ($wijzigingen as [$post, $code]) {
            $this->addSql('UPDATE post SET code = ? WHERE id = ?', [$code, $post['id']]);
        }

        // DBAL-migraties lopen buiten de ORM-listener: leg de uiteindelijke correcties apart vast.
        $requestId = bin2hex(random_bytes(16));
        foreach ($wijzigingen as [$post, $code]) {
            $this->addSql('INSERT INTO handeling (datum, afdeling_id, actor_id, actor, actie, object_type, object_id, route, request_id, voor, na) VALUES (?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?)', [
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'Databankmigratie', 'post corrigeren', Post::class, $post['id'],
                'migration:Version20261023160000', $requestId,
                json_encode(['code' => $post['code']], JSON_THROW_ON_ERROR),
                json_encode(['code' => $code], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Deze migratie herstelt onjuiste postafkortingen. De foutieve toewijzing wordt niet automatisch teruggezet.');
    }
}
