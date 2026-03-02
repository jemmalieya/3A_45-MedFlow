<?php

namespace App\Service;

use Stripe\Stripe;
use Stripe\Checkout\Session as StripeCheckoutSession;

class StripePaymentService
{
    private string $stripeSecretKey;
    private string $appUrl;

    private string $stripeCurrency = 'eur';
    private float $dtToEurRate = 0.30;

    public function __construct(string $stripeSecretKey, string $appUrl)
    {
        $this->stripeSecretKey = $stripeSecretKey;
        $this->appUrl = rtrim($appUrl, '/');
    }

    /**
     * @param array<int, array{name?: string, unit_price_dt?: float|int, quantity?: int}> $items
     */
    public function createCheckoutSession(int $commandeId, array $items): StripeCheckoutSession
    {
        if (empty($items)) {
            throw new \InvalidArgumentException("Panier vide : impossible de créer une session Stripe.");
        }

        Stripe::setApiKey($this->stripeSecretKey);

        $lineItems = [];

        foreach ($items as $item) {
            $name = (string) ($item['name'] ?? 'Produit');
            $priceDt = (float) ($item['unit_price_dt'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 1);

            if ($priceDt <= 0 || $qty <= 0) {
                continue;
            }

            $priceEur = $priceDt * $this->dtToEurRate;
            $unitAmount = (int) round($priceEur * 100);

            if ($unitAmount < 1) {
                $unitAmount = 1;
            }

            $lineItems[] = [
                'price_data' => [
                    'currency' => $this->stripeCurrency,
                    'product_data' => [
                        'name' => $name,
                    ],
                    'unit_amount' => $unitAmount,
                ],
                'quantity' => $qty,
            ];
        }

        if (empty($lineItems)) {
            throw new \InvalidArgumentException("Aucun produit valide pour Stripe (prix/quantité invalides).");
        }

        return StripeCheckoutSession::create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'success_url' => $this->appUrl . '/commande/paiement/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $this->appUrl . '/commande/paiement/cancel',
            'metadata' => [
                'commande_id' => (string) $commandeId,
            ],
        ]);
    }

    public function retrieveSession(string $sessionId): StripeCheckoutSession
    {
        Stripe::setApiKey($this->stripeSecretKey);
        return StripeCheckoutSession::retrieve($sessionId);
    }

    public function convertDtToEur(float $amountDt): float
    {
        return $amountDt * $this->dtToEurRate;
    }

    public function getStripeCurrency(): string
    {
        return $this->stripeCurrency;
    }
}