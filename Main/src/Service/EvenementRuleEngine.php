<?php

namespace App\Service;

use App\Entity\Evenement;
use App\Entity\User;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class EvenementRuleEngine
{
    private ExpressionLanguage $exp;

    public function __construct()
    {
        $this->exp = new ExpressionLanguage();
    }

    /**
     * @return array{
     *   context: array{
     *     type:string,
     *     ville:string,
     *     statut:string,
     *     max:int
     *   },
     *   matched: array<int, array{
     *     label:string,
     *     expr:string,
     *     badge:string
     *   }>
     * }
     */
    public function evaluate(Evenement $ev, ?User $user = null): array
    {
        $ctx = [
            'type'   => (string) $ev->getTypeEvent(),
            'ville'  => (string) $ev->getVilleEvent(),
            'statut' => strtolower((string) $ev->getStatutEvent()),
            'max'    => (int) ($ev->getNbParticipantsMaxEvent() ?? 0),
        ];

        $rules = [
            ['label' => 'Événement à promouvoir', 'expr' => 'statut == "publié" and max >= 50', 'badge' => 'success'],
            ['label' => 'Risque organisation (petite capacité)', 'expr' => 'max > 0 and max < 10', 'badge' => 'warning'],
            ['label' => 'Cas critique (annulé)', 'expr' => 'statut == "annulé" or statut == "annule"', 'badge' => 'danger'],
        ];

        $matched = [];
        foreach ($rules as $r) {
            try {
                if ((bool) $this->exp->evaluate($r['expr'], $ctx)) {
                    $matched[] = $r;
                }
            } catch (\Throwable $e) {
                // ignore règle invalide
            }
        }

        return [
            'context' => $ctx,
            'matched' => $matched,
        ];
    }
}