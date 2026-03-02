<?php

namespace App\Service;

use LanguageDetection\Language;
use Phpml\Classification\NaiveBayes;
use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\Tokenization\WhitespaceTokenizer;

class ReclamationMlPriorityService
{
    private Language $language;
    private NaiveBayes $classifier;
    private TokenCountVectorizer $vectorizer;
    private NaiveBayes $sentimentClassifier;
    private TokenCountVectorizer $sentimentVectorizer;

    public function __construct()
    {
        $this->language = new Language();
        $this->classifier = new NaiveBayes();
        $this->vectorizer = new TokenCountVectorizer(new WhitespaceTokenizer());
        $this->sentimentClassifier = new NaiveBayes();
        $this->sentimentVectorizer = new TokenCountVectorizer(new WhitespaceTokenizer());
        $this->trainSentiment();

        $this->train();
    }
private function trainSentiment(): void
{
    $samples = [
        // NEGATIVE
        "c'est inacceptable je suis en colère",
        "service horrible personnel impoli",
        "j'ai souffert et personne n'a aidé",
        "retard excessif très frustrant",
        "worst service ever",
        "very bad experience",

        // POSITIVE
        "merci pour votre aide",
        "service excellent personnel gentil",
        "je suis très satisfait",
        "bonne expérience",
        "great service thank you",
        "everything was perfect",

        // NEUTRAL
        "je demande une information",
        "rendez vous reporté",
        "besoin d'un renseignement",
        "appointment rescheduled",
        "general question",
    ];

    $labels = [
        "NEGATIVE","NEGATIVE","NEGATIVE","NEGATIVE","NEGATIVE","NEGATIVE",
        "POSITIVE","POSITIVE","POSITIVE","POSITIVE","POSITIVE","POSITIVE",
        "NEUTRAL","NEUTRAL","NEUTRAL","NEUTRAL","NEUTRAL",
    ];

    $vectorSamples = $samples;
    $this->sentimentVectorizer->fit($vectorSamples);
    $this->sentimentVectorizer->transform($vectorSamples);

    $this->sentimentClassifier->train($vectorSamples, $labels);
}
    private function train(): void
    {
        $samples = [
            // CRITIQUE
            "urgent severe pain bleeding emergency",
            "medical error wrong diagnosis danger",
            "je souffre tres forte douleur urgent",
            "خطير عاجل نزيف ألم شديد",
            "besoin urgent intervention immediat",
            "service urgence accident grave",

            // ELEVEE
            "attente 3 heures retard excessive",
            "personnel medical rude mauvais traitement",
            "annulation rendez vous sans prevenir",
            "delai trop long aucun support",
            "pharmacie medicament manquant important",

            // NORMALE
            "probleme facturation paiement double",
            "assurance prise en charge refus",
            "dossier medical information manquante",
            "laboratoire resultats en retard",
            "radiologie rendez vous reporte",

            // BASSE
            "suggestion amelioration accueil",
            "feedback general organisation",
            "thank you everything was fine",
            "information demande simple",
        ];

        $labels = [
            "CRITIQUE","CRITIQUE","CRITIQUE","CRITIQUE","CRITIQUE","CRITIQUE",
            "ELEVEE","ELEVEE","ELEVEE","ELEVEE","ELEVEE",
            "NORMALE","NORMALE","NORMALE","NORMALE","NORMALE",
            "BASSE","BASSE","BASSE","BASSE",
        ];

        // Fit/transform
        $vectorSamples = $samples;
        $this->vectorizer->fit($vectorSamples);
        $this->vectorizer->transform($vectorSamples);

        $this->classifier->train($vectorSamples, $labels);
    }

/**
 * @return array{
 *     lang: string,
 *     priority: string,
 *     urgenceScore: int,
 *     sentiment: string
 * }
 */
public function analyze(string $contenu, string $description, ?string $type = null): array
    {
        $text = trim($contenu . " " . $description);

        // 1) Langue
        $lang = "unknown";
        try {
            $best = $this->language->detect($text)->bestResults()->close();
            $lang = $best[0] ?? "unknown";
        } catch (\Throwable $e) {
        }

      // 2) Vectorize
$sample = [$text];
$this->vectorizer->transform($sample);

// 3) Predict : retourne un tableau de labels
$pred = $this->classifier->predict($sample);

// ✅ label final
$priority = $pred[0];

        // 3) Score urgence (variable + logique)
        $score = match ($priority) {
            "CRITIQUE" => 90,
            "ELEVEE"   => 65,
            "NORMALE"  => 35,
            default    => 10,
        };

        // 4) Ajustement par type
        if ($type === "URGENCE") {
            $priority = "CRITIQUE";
            $score = max($score, 95);
        }
        if ($type === "ERREUR_MEDICALE") {
            $score = max($score, 70);
            if ($priority === "BASSE") $priority = "NORMALE";
        }
// 5) Sentiment (ML externe)
$sentSample = [$text];
$this->sentimentVectorizer->transform($sentSample);

$predictions = $this->sentimentClassifier->predict($sentSample);
$sentiment = $predictions[0];
      return [
    "lang" => $lang,
    "priority" => $priority,
    "urgenceScore" => $score,
    "sentiment" => $sentiment,
];
    }
}