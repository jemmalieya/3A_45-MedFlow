<?php

namespace App\Service;

use Stripe\Stripe;
use Stripe\Checkout\Session as StripeCheckoutSession;

class StripePaymentService
{
    private string $stripeSecretKey;
    private string $appUrl;

    // ✅ Stripe ne supporte pas TND → on facture en EUR
    private string $stripeCurrency = 'eur';

    // ✅ Taux fixe (projet) : 1 DT ≈ 0.30 EUR
    // (tu peux le changer quand tu veux)
    private float $dtToEurRate = 0.30;

    public function __construct(string $stripeSecretKey, string $appUrl)
    {
        $this->stripeSecretKey = $stripeSecretKey;
        $this->appUrl = rtrim($appUrl, '/');
    }

    /**
     * $items format:
     * [
     *   ['name' => 'Produit A', 'unit_price_dt' => 12.5, 'quantity' => 2],
     *   ['name' => 'Produit B', 'unit_price_dt' => 5.0,  'quantity' => 1],
     * ]
     */
    public function createCheckoutSession(int $commandeId, array $items): StripeCheckoutSession
    {
        if (empty($items)) {
            throw new \InvalidArgumentException("Panier vide : impossible de créer une session Stripe.");
        }

        Stripe::setApiKey($this->stripeSecretKey);

        $lineItems = [];

        foreach ($items as $item) {
            $name = (string)($item['name'] ?? 'Produit');
            $priceDt = (float)($item['unit_price_dt'] ?? 0);
            $qty = (int)($item['quantity'] ?? 1);

            if ($priceDt <= 0 || $qty <= 0) {
                continue;
            }

            // ✅ Convert DT -> EUR
            $priceEur = $priceDt * $this->dtToEurRate;

            // ✅ Stripe attend des "cents" (EUR * 100)
            $unitAmount = (int) round($priceEur * 100);

            // Stripe refuse 0
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
                'commande_id' => $commandeId,
            ],
        ]);
    }

    public function retrieveSession(string $sessionId): StripeCheckoutSession
    {
        Stripe::setApiKey($this->stripeSecretKey);
        return StripeCheckoutSession::retrieve($sessionId);
    }

    // ✅ Optionnel: pour afficher estimation EUR sur la page
    public function convertDtToEur(float $amountDt): float
    {
        return $amountDt * $this->dtToEurRate;
    }

    public function getStripeCurrency(): string
    {
        return $this->stripeCurrency;
    }
}
