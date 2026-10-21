<?php

namespace App\Service;

use App\Entity\Afdeling;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Verstuurt groepsmails. Ontvangers staan in BCC zodat adressen niet gedeeld worden
 * (de oude toepassing zette iedereen zichtbaar in "Aan").
 */
final class Mailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $mailFrom,
    ) {
    }

    /**
     * @param string[] $ontvangers
     * @param array<string, mixed> $context
     */
    public function stuur(Afdeling $afdeling, array $ontvangers, string $onderwerp, string $template, array $context = []): int
    {
        $ontvangers = array_values(array_unique(array_filter(array_map('trim', $ontvangers), static fn (string $a) => false !== filter_var($a, FILTER_VALIDATE_EMAIL))));
        if (!$ontvangers) {
            return 0;
        }
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, $afdeling->getTitel()))
            ->to(new Address($this->mailFrom, $afdeling->getTitel()))
            ->bcc(...$ontvangers)
            ->subject($afdeling->getTitel().' - '.$onderwerp)
            ->htmlTemplate($template)
            ->context($context + ['afdeling' => $afdeling]);
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Een mailprobleem mag het afsluiten van een lijst of foutmelding nooit blokkeren.
            $this->logger->error('Mail niet verstuurd: {fout}', ['fout' => $e->getMessage(), 'onderwerp' => $onderwerp]);
            return 0;
        }
        return \count($ontvangers);
    }
}
