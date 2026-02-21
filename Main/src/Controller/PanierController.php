<?php

namespace App\Controller;

use App\Entity\Produit;
use App\Service\DrugInteractionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\OcrService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[Route('/panier')]
class PanierController extends AbstractController
{
    #[Route('', name: 'panier_index', methods: ['GET'])]
    public function index(
        SessionInterface $session,
        EntityManagerInterface $em,
        DrugInteractionService $interactionService
    ): Response {
        $panier = $session->get('panier', []);

        $produitsPanier = [];
        $total = 0;

        foreach ($panier as $id => $item) {
            $produit = $em->getRepository(Produit::class)->find($id);
            if (!$produit) continue;

            $qty = (int)($item['quantite'] ?? 0);
            if ($qty <= 0) continue;

            $produit->quantite_panier = $qty;
            $produitsPanier[] = $produit;

            $total += ((float)$produit->getPrixProduit()) * $qty;
        }

        $interactionResult = $interactionService->checkCartInteractions($panier);
        $canValidate = true;

        if (($interactionResult['severity'] ?? null) === 'danger') {
            $canValidate = false;

            $ok = (bool) $session->get('ordonnance_ok', false);
            $hash = $this->cartHash($panier);
            $hashSaved = (string) $session->get('ordonnance_cart_hash', '');

            if ($ok && $hashSaved === $hash) {
                $canValidate = true;
            }
        }

        // ✅ RÉCUPÉRER LE STATUT PUIS LE SUPPRIMER
        $ordonnanceStatus = $session->get('ordonnance_status');
        $ordonnanceDrugs = $session->get('ordonnance_drugs_found', []);
        $ordonnanceOcrText = $session->get('ordonnance_ocr_text');
        
        // ✅ NETTOYER IMMÉDIATEMENT (affiché une seule fois)
        $session->remove('ordonnance_status');
        $session->remove('ordonnance_drugs_found');
        $session->remove('ordonnance_ocr_text');

        return $this->render('panier/index.html.twig', [
            'produits' => $produitsPanier,
            'total' => $total,
            'interactionResult' => $interactionResult,
            'canValidate' => $canValidate,
            'ordonnanceStatus' => $ordonnanceStatus,
            'ordonnanceDrugs' => $ordonnanceDrugs,
            'ordonnanceOcrText' => $ordonnanceOcrText,
        ]);
    }

    #[Route('/check-interactions', name: 'panier_check_interactions', methods: ['GET'])]
    public function checkInteractions(
        SessionInterface $session,
        DrugInteractionService $interactionService
    ): JsonResponse {
        $panier = $session->get('panier', []);
        return new JsonResponse($interactionService->checkCartInteractions($panier));
    }

    #[Route('/count', name: 'panier_count', methods: ['GET'])]
    public function count(SessionInterface $session): JsonResponse
    {
        return new JsonResponse(['count' => $this->getCount($session->get('panier', []))]);
    }

    #[Route('/verifier/{id}', name: 'panier_verifier', methods: ['GET'])]
    public function verifier(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        return new JsonResponse([
            'quantite' => isset($panier[$id]) ? (int)($panier[$id]['quantite'] ?? 0) : 0
        ]);
    }

    #[Route('/ajouter/{id}', name: 'panier_ajouter', methods: ['POST','GET'])]
    public function ajouter(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        $stock = (int) ($produit->getQuantiteProduit() ?? 0);
        $quantiteDansPanier = isset($panier[$id]) ? (int)($panier[$id]['quantite'] ?? 0) : 0;

        if ($stock > 0 && $quantiteDansPanier >= $stock) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Stock insuffisant !',
                'count' => $this->getCount($panier)
            ], 400);
        }

        $panier[$id]['quantite'] = ((int)($panier[$id]['quantite'] ?? 0)) + 1;
        $panier[$id]['prix'] = $produit->getPrixProduit();

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'message' => $produit->getNomProduit().' ajouté au panier',
            'count' => $this->getCount($panier),
            'quantite' => (int)$panier[$id]['quantite'],
        ]);
    }

    #[Route('/augmenter/{id}', name: 'panier_augmenter', methods: ['POST'])]
    public function augmenter(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        if (!isset($panier[$id])) {
            $panier[$id]['quantite'] = 0;
        }

        $stock = (int) ($produit->getQuantiteProduit() ?? 0);
        $panier[$id]['quantite'] = ((int)($panier[$id]['quantite'] ?? 0)) + 1;

        if ($stock > 0 && $panier[$id]['quantite'] > $stock) {
            $panier[$id]['quantite'] = $stock;
            $session->set('panier', $panier);

            return new JsonResponse([
                'success' => false,
                'message' => 'Stock épuisé',
                'quantite' => (int)$panier[$id]['quantite'],
                'count' => $this->getCount($panier)
            ], 400);
        }

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'quantite' => (int)$panier[$id]['quantite'],
            'count' => $this->getCount($panier)
        ]);
    }

    #[Route('/diminuer/{id}', name: 'panier_diminuer', methods: ['POST'])]
    public function diminuer(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $id = $produit->getId_produit();

        if (!isset($panier[$id])) {
            return new JsonResponse(['success' => false, 'message' => 'Produit absent'], 400);
        }

        $panier[$id]['quantite'] = ((int)$panier[$id]['quantite']) - 1;

        if ($panier[$id]['quantite'] <= 0) {
            unset($panier[$id]);
            $session->set('panier', $panier);

            return new JsonResponse([
                'success' => true,
                'quantite' => 0,
                'count' => $this->getCount($panier)
            ]);
        }

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'quantite' => (int)$panier[$id]['quantite'],
            'count' => $this->getCount($panier)
        ]);
    }

    #[Route('/supprimer/{id}', name: 'panier_supprimer', methods: ['POST'])]
    public function supprimer(Produit $produit, SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        unset($panier[$produit->getId_produit()]);
        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'count' => $this->getCount($panier)
        ]);
    }

    #[Route('/vider', name: 'panier_vider', methods: ['POST'])]
    public function vider(SessionInterface $session): JsonResponse
    {
        $session->remove('panier');
        $session->remove('ordonnance_ok');
        $session->remove('ordonnance_cart_hash');
        
        return new JsonResponse(['success' => true, 'count' => 0]);
    }

    private function getCount(array $panier): int
    {
        return array_sum(array_map(fn($i) => (int)($i['quantite'] ?? 0), $panier));
    }

    private function cartHash(array $panier): string
    {
        ksort($panier);
        return hash('sha256', json_encode($panier));
    }

    private function normalize(string $s): string
    {
        $s = mb_strtoupper($s);
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = preg_replace('/[^A-Z0-9\s]/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    #[Route('/ordonnance/scan', name: 'panier_ordonnance_scan', methods: ['POST'])]
    public function scanOrdonnance(
        Request $request,
        SessionInterface $session,
        DrugInteractionService $interactionService,
        OcrService $ocr
    ): Response {
        $panier = $session->get('panier', []);
        if (empty($panier)) {
            $this->addFlash('error', 'Panier vide.');
            return $this->redirectToRoute('panier_index');
        }

        $interaction = $interactionService->checkCartInteractions($panier);
        if (($interaction['severity'] ?? null) !== 'danger') {
            $this->addFlash('info', 'Pas d\'interaction dangereuse → ordonnance non nécessaire.');
            return $this->redirectToRoute('panier_index');
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('ordonnance');
        if (!$file) {
            $this->addFlash('error', 'Veuillez choisir une image d\'ordonnance.');
            return $this->redirectToRoute('panier_index');
        }

        $ext = strtolower((string) $file->guessExtension());
        if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
            $this->addFlash('error', 'Format non supporté. Utilisez JPG/PNG/WEBP.');
            return $this->redirectToRoute('panier_index');
        }

        $dir = $this->getParameter('kernel.project_dir') . '/public/uploads/ordonnances';
        @mkdir($dir, 0777, true);

        $filename = 'ord_' . uniqid() . '.' . $ext;
        $file->move($dir, $filename);
        $path = $dir . '/' . $filename;

        try {
            $text = $ocr->extractText($path, 'fre');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'OCR échoué: '.$e->getMessage());
            return $this->redirectToRoute('panier_index');
        }

        $ocrNorm = $this->normalize($text);

        $pairs = $interaction['interactions'] ?? [];
        $ok = false;
        $foundDrugs = [];

        foreach ($pairs as $it) {
            $d1 = $this->normalize((string)($it['drug1'] ?? ''));
            $d2 = $this->normalize((string)($it['drug2'] ?? ''));

            if ($d1 && $d2 && str_contains($ocrNorm, $d1) && str_contains($ocrNorm, $d2)) {
                $ok = true;
                $foundDrugs[] = $it['drug1'];
                $foundDrugs[] = $it['drug2'];
                break;
            }
        }

        if (!$ok) {
            $this->addFlash('error', 'Ordonnance scannée mais médicaments non détectés.');
            $session->set('ordonnance_status', 'refused');
            $session->set('ordonnance_ocr_text', mb_substr($text, 0, 300));
            return $this->redirectToRoute('panier_index');
        }

        $hash = $this->cartHash($panier);
        $session->set('ordonnance_ok', true);
        $session->set('ordonnance_cart_hash', $hash);
        $session->set('ordonnance_status', 'approved');
        $session->set('ordonnance_drugs_found', array_unique($foundDrugs));

        $this->addFlash('success', 'Ordonnance validée ✅ Vous pouvez valider la commande.');
        
        return $this->redirectToRoute('panier_index');
    }
}