<?php

namespace App\Controller;

use App\Entity\Produit;
use App\Entity\User;
use App\Form\ProduitType;
use App\Repository\ProduitRepository;
use App\Repository\CommandeRepository;

use App\Service\AiPharmacyRecommender;
use App\Service\GroqService;
use App\Service\QrCodeService;
use App\Service\VCardService;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Notification\Notification;

use Knp\Component\Pager\PaginatorInterface;

final class ProduitsController extends AbstractController
{
    #[Route('/produits', name: 'front_produit_index', methods: ['GET'])]
    public function frontIndex(Request $request, ProduitRepository $repo): Response
    {
        $search    = (string) $request->query->get('search', '');
        $category  = (string) $request->query->get('category', '');
        $sortPrice = (string) $request->query->get('sort', '');
        $sortStock = (string) $request->query->get('sortStock', '');

        $produits   = $repo->findFiltered($search, $category, $sortPrice, $sortStock);
        $categories = $repo->findAllCategories();

        return $this->render('produits/index.html.twig', [
            'produits'         => $produits,
            'categories'       => $categories,
            'currentSearch'    => $search,
            'currentCategory'  => $category,
            'currentSort'      => $sortPrice,
            'currentSortStock' => $sortStock,
        ]);
    }

    #[Route('/api/pharmacie/ai', name: 'api_pharmacie_ai', methods: ['POST'])]
    public function pharmacieAi(Request $request, GroqService $groq): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $message = trim((string) ($data['message'] ?? ''));

        if ($message === '' || mb_strlen($message) > 600) {
            return $this->json(['ok' => false, 'reply' => "Message invalide (vide ou trop long)."], 400);
        }

        try {
            return $this->json(['ok' => true, 'reply' => $groq->chat($message)]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'reply' => "Erreur IA. Réessaie plus tard."], 500);
        }
    }

    #[Route('/admin/produits', name: 'admin_produits_index', methods: ['GET'])]
    public function adminIndex(
        Request $request,
        ProduitRepository $repo,
        PaginatorInterface $paginator,
        NotifierInterface $notifier
    ): Response {
        $search   = (string) $request->query->get('search', '');
        $category = (string) $request->query->get('category', '');

        $qb = $repo->createQueryBuilder('p')->orderBy('p.id_produit', 'DESC');

        if ($search !== '') {
            $qb->andWhere('LOWER(p.nom_produit) LIKE :s OR LOWER(p.categorie_produit) LIKE :s')
               ->setParameter('s', '%' . mb_strtolower($search) . '%');
        }
        if ($category !== '') {
            $qb->andWhere('p.categorie_produit = :c')
               ->setParameter('c', $category);
        }

        $pagination = $paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            5
        );

        // ✅ ALERTES INVENTAIRE
        $seuilFaible = 10;
        $allProduits = $repo->findAll();

        $rupture = array_values(array_filter($allProduits, fn($p) =>
            strtolower(trim((string) $p->getStatusProduit())) === 'rupture'
        ));

        $stockFaible = array_values(array_filter($allProduits, fn($p) =>
            $p->getQuantiteProduit() !== null && (int) $p->getQuantiteProduit() <= $seuilFaible
        ));

        if (count($rupture) > 0) {
            $msg = count($rupture) . " produit(s) en rupture. Réapprovisionnement recommandé.";
            $this->addFlash('error', $msg);
            $notifier->send(new Notification($msg, ['browser']));
        }

        if (count($stockFaible) > 0) {
            $msg = count($stockFaible) . " produit(s) en stock faible (≤ $seuilFaible).";
            $this->addFlash('warning', $msg);
            $notifier->send(new Notification($msg, ['browser']));
        }

        return $this->render('admin/index_produit.html.twig', [
            'pagination'   => $pagination,
            'search'       => $search,
            'category'     => $category,
            'ruptureList'  => $rupture,
            'lowStockList' => $stockFaible,
            'seuilFaible'  => $seuilFaible,
        ]);
    }

    #[Route('/admin/produits/new', name: 'admin_produit_new', methods: ['GET', 'POST'])]
public function new(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
{
    $produit = new Produit();

    // ✅ PAS DE image_input
    $form = $this->createForm(ProduitType::class, $produit, [
        'mode' => 'create',
    ]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

        /** @var UploadedFile|null $imageFile */
        $imageFile = $form->get('imageFile')->getData();

        // ✅ Forcer image obligatoire (create)
        if (!$imageFile) {
            $this->addFlash('error', "Veuillez choisir une image (upload).");
            return $this->render('admin/newProduit.html.twig', [
                'form' => $form->createView(),
            ]);
        }

        $original = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = $slugger->slug($original);
        $newFilename = $safeName . '-' . uniqid() . '.' . ($imageFile->guessExtension() ?: 'jpg');

        $imageFile->move($this->getParameter('produits_images_dir'), $newFilename);

        // ✅ Sauver juste le nom du fichier
        $produit->setImageProduit($newFilename);

        $em->persist($produit);
        $em->flush();

        $this->addFlash('success', 'Produit ajouté avec succès !');
        return $this->redirectToRoute('admin_produits_index');
    }

    return $this->render('admin/newProduit.html.twig', [
        'form' => $form->createView(),
    ]);
}
    #[Route('/admin/produits/{id}/edit', name: 'admin_produit_edit', methods: ['GET', 'POST'])]
    public function edit(Produit $produit, Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $oldImage = $produit->getImageProduit();

        $form = $this->createForm(ProduitType::class, $produit, [
            'mode' => 'edit',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->has('imageFile') ? $form->get('imageFile')->getData() : null;

            if ($imageFile) {
                $original = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeName = $slugger->slug($original);
                $newFilename = $safeName . '-' . uniqid() . '.' . ($imageFile->guessExtension() ?: 'jpg');

                $imageFile->move($this->getParameter('produits_images_dir'), $newFilename);
                $produit->setImageProduit($newFilename);

                if ($oldImage && !str_starts_with($oldImage, 'http')) {
                    $oldPath = $this->getParameter('produits_images_dir') . '/' . $oldImage;
                    if (is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                }
            } else {
                $current = trim((string) $produit->getImageProduit());
                if ($current === '') {
                    $produit->setImageProduit($oldImage);
                }
            }

            $em->flush();

            $this->addFlash('success', 'Produit modifié avec succès !');
            return $this->redirectToRoute('admin_produits_index');
        }

        return $this->render('admin/editProduit.html.twig', [
            'produit'  => $produit,
            'form'     => $form->createView(),
            'oldImage' => $oldImage,
        ]);
    }

    #[Route('/admin/produits/{id}/delete', name: 'admin_produit_delete', methods: ['POST'])]
    public function delete(Produit $produit, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $produit->getId_produit(), (string) $request->request->get('_token'))) {
            $img = $produit->getImageProduit();

            $em->remove($produit);
            $em->flush();

            if ($img && !str_starts_with($img, 'http')) {
                $path = $this->getParameter('produits_images_dir') . '/' . $img;
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            $this->addFlash('success', 'Produit supprimé avec succès !');
        }

        return $this->redirectToRoute('admin_produits_index');
    }

    #[Route('/produits/api/best-sellers', name: 'produits_api_best_sellers', methods: ['GET'])]
    public function apiBestSellers(CommandeRepository $cr): JsonResponse
    {
        $produits = $cr->getBestSellersNonMedicaments(12);
        $fallback = null;

        if (empty($produits)) {
            $produits = $cr->getBestSellersGlobal(12);
            $fallback = 'global';
        }

        $items = array_map(fn($p) => [
            'id' => $p->getId_produit(),
            'nom' => $p->getNomProduit(),
            'prix' => (float) $p->getPrixProduit(),
            'image' => $p->getImageProduit()
                ? (str_starts_with($p->getImageProduit(), 'http') ? $p->getImageProduit() : '/uploads/produits/' . $p->getImageProduit())
                : null,
            'categorie' => $p->getCategorieProduit(),
        ], $produits);

        return new JsonResponse([
            'success' => true,
            'fallback' => $fallback,
            'items' => $items,
        ]);
    }

    #[Route('/produits/api/reco-ai', name: 'produits_api_reco_ai', methods: ['GET'])]
    public function apiRecoAi(
        Request $request,
        AiPharmacyRecommender $reco,
        CommandeRepository $cr,
        ProduitRepository $pr
    ): JsonResponse {
        // ✅ Toujours initialiser
        $user = $this->getUser();
        $userId = $user instanceof User ? (int) $user->getId() : null;
    
        // Session (visiteur)
        $session = $request->getSession();
        if (!$session->has('reco_session_id')) {
            $session->set('reco_session_id', bin2hex(random_bytes(8)));
        }
        $sessionId = (string) $session->get('reco_session_id');
    
        $basedOn = null;
        $mode = 'personalized';
        $explainText = "Basé sur votre historique d'achat (hors médicaments).";
    
        $items = [];
    
        if ($userId) {
            $items = $reco->recommendFromHistory($userId, 12);
    
            $topId = $cr->getUserTopProductId($userId);
            if ($topId) {
                $p = $pr->find($topId);
                if ($p) {
                    $basedOn = [
                        'topProduct' => $p->getNomProduit(),
                        'category' => $p->getCategorieProduit(),
                    ];
                    $explainText = "Basé sur votre produit le plus acheté : {$basedOn['topProduct']} (catégorie : {$basedOn['category']}).";
                }
            } else {
                $explainText = "Pas assez d'historique : suggestions basées sur les tendances du moment.";
            }
        } else {
            $mode = 'session';
            $explainText = "Suggestions adaptées à votre session (visiteur) — connectez-vous pour des recommandations basées sur vos achats.";
        }
    
        if (empty($items)) {
            $trending = $cr->getBestSellersNonMedicaments(12);
    
            if (!empty($trending)) {
                if ($userId) {
                    // ordre personnalisé par user connecté
                    usort($trending, function($a, $b) use ($userId) {
                        $ha = crc32($userId . '-' . $a->getId_produit());
                        $hb = crc32($userId . '-' . $b->getId_produit());
                        return $ha <=> $hb;
                    });
                    $mode = 'trending_user';
                    $explainText = "Tendances du moment (hors médicaments) — ordre personnalisé pour vous.";
                } else {
                    // ordre personnalisé par session (visiteur)
                    usort($trending, function($a, $b) use ($sessionId) {
                        $ha = crc32($sessionId . '-' . $a->getId_produit());
                        $hb = crc32($sessionId . '-' . $b->getId_produit());
                        return $ha <=> $hb;
                    });
                    $mode = 'session_trending';
                    $explainText = "Tendances du moment (hors médicaments) — ordre adapté à votre session.";
                }
    
                $items = array_slice($trending, 0, 12);
            }
        }
    
        // 🔒 Fallback final pour ne jamais renvoyer vide
        if (empty($items)) {
            $items = $pr->createQueryBuilder('p')
                ->andWhere('LOWER(p.status_produit) = :st')->setParameter('st', 'disponible')
                ->orderBy('p.id_produit', 'DESC')
                ->setMaxResults(12)
                ->getQuery()
                ->getResult();
    
            $mode = 'fallback_final';
            $explainText = "Suggestions disponibles.";
        }
    
        $payloadItems = array_map(function($p) use ($userId, $basedOn, $mode) {
            $badges = [];
    
            if ($mode !== 'fallback_final') $badges[] = '✨ Recommandé';
            if ($userId && $basedOn && $p->getCategorieProduit() === $basedOn['category']) {
                $badges[] = '🏷️ Même catégorie';
            }
    
            $img = $p->getImageProduit();
    
            return [
                'id' => $p->getId_produit(),
                'nom' => $p->getNomProduit(),
                'prix' => (float) $p->getPrixProduit(),
                'image' => $img ? (str_starts_with($img, 'http') ? $img : '/uploads/produits/' . $img) : null,
                'categorie' => $p->getCategorieProduit(),
                'badges' => $badges,
            ];
        }, $items);
    
        return new JsonResponse([
            'success' => true,
            'mode' => $mode,
            'userId' => $userId,
            'basedOn' => $basedOn,
            'explainText' => $explainText,
            'items' => $payloadItems,
        ]);
    }

   // ProduitsController.php
   #[Route('/contact-pharmacie/qr.json', name: 'front_contact_pharmacie_qr_json', methods: ['GET'])]
   public function contactQr(QrCodeService $qr, VCardService $vcard): JsonResponse
   {
       try {
           $vcardText = $vcard->buildPharmacyVCard([
               'name' => 'Pharmacie MedFlow',
               'org' => 'MedFlow',
               'phone' => '+216 22 222 222',
               'email' => 'pharmacie@medflow.tn',
               'address' => 'Rue Exemple 12',
               'city' => 'Tunis',
               'zip' => '1000',
               'country' => 'TN',
           ]);
   
           // ✅ IMPORTANT: PAS d'extension ici
           $baseName = 'contact_pharmacie_' . date('Ymd_His');

           // PNG nécessite ext-gd. Fallback SVG si GD n'est pas dispo.
           if (extension_loaded('gd')) {
               $qrData = $qr->generatePng($vcardText, $baseName);
           } else {
               $qrData = $qr->generateSvg($vcardText, $baseName);
           }
   
           return $this->json([
               'ok' => true,
               // ✅ on renvoie une string (pas un objet)
               'qrPath' => $qrData['publicPath'],
           ]);
       } catch (\Throwable $e) {
           return $this->json([
               'ok' => false,
               'error' => $e->getMessage(),
           ], 500);
       }
   }
}