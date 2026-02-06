<?php

namespace App\Controller;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class CommandeController extends AbstractController
{
    /* =========================
     *         BACK (ADMIN)
     * ========================= */

    #[Route('/admin/commandes', name: 'admin_commandes_index', methods: ['GET'])]
    public function adminIndex(EntityManagerInterface $em): Response
    {
        $commandes = $em->getRepository(Commande::class)->findBy(
            [],
            ['date_creation_commande' => 'DESC']
        );

        return $this->render('admin/commande.html.twig', [
            'commandes' => $commandes
        ]);
    }

    #[Route('/admin/commandes/{id}', name: 'admin_commande_show', methods: ['GET'])]
    public function adminShow(Commande $commande): Response
    {
        return $this->render('admin/commande.html.twig', [
            'commande' => $commande
        ]);
    }

    #[Route('/admin/commandes/{id}/statut', name: 'admin_commande_statut', methods: ['POST'])]
    public function adminChangerStatut(
        Request $request,
        Commande $commande,
        EntityManagerInterface $em
    ): Response {
        $nouveauStatut = $request->request->get('statut');

        $statutsAutorises = ['En attente', 'En cours', 'Expédiée', 'Livrée', 'Annulée'];

        if (in_array($nouveauStatut, $statutsAutorises, true)) {
            $commande->setStatutCommande($nouveauStatut);
            $em->flush();
            $this->addFlash('success', 'Statut mis à jour avec succès !');
        } else {
            $this->addFlash('error', 'Statut invalide');
        }

        return $this->redirectToRoute('admin_commande_show', [
            'id' => $commande->getIdCommande()
        ]);
    }

    #[Route('/admin/commandes/{id}/delete', name: 'admin_commande_delete', methods: ['POST'])]
    public function adminDelete(Commande $commande, EntityManagerInterface $em): Response
    {
        foreach ($commande->getLigneCommandes() as $ligne) {
            $em->remove($ligne);
        }

        $em->remove($commande);
        $em->flush();

        $this->addFlash('success', 'Commande supprimée avec succès');
        return $this->redirectToRoute('admin_commandes_index');
    }

    /* =========================
     *         FRONT (USER)
     * ========================= */

    #[Route('/valider', name: 'commande_valider', methods: ['GET'])]
    public function valider(SessionInterface $session, EntityManagerInterface $em): Response
    {
        $panier = $session->get('panier', []);

        if (empty($panier)) {
            $this->addFlash('error', 'Votre panier est vide');
            return $this->redirectToRoute('front_produit_index');
        }

        $produitsPanier = [];
        $total = 0;

        foreach ($panier as $id => $item) {
            $produit = $em->getRepository(Produit::class)->find($id);
            if (!$produit) {
                continue;
            }

            if ($produit->getStatusProduit() !== 'Disponible') {
                $this->addFlash('error', $produit->getNomProduit() . ' n\'est plus disponible');
                return $this->redirectToRoute('front_produit_index');
            }

            if ($produit->getQuantiteProduit() < $item['quantite']) {
                $this->addFlash('error', 'Stock insuffisant pour ' . $produit->getNomProduit());
                return $this->redirectToRoute('front_produit_index');
            }

            // propriété temporaire juste pour twig (comme tu as fait)
            $produit->quantite_panier = $item['quantite'];

            $produitsPanier[] = $produit;
            $total += $produit->getPrixProduit() * $item['quantite'];
        }

        return $this->render('commande/valider.html.twig', [
            'produits' => $produitsPanier,
            'total' => $total
        ]);
    }

    #[Route('/confirmer', name: 'commande_confirmer', methods: ['POST'])]
    public function confirmer(SessionInterface $session, EntityManagerInterface $em): Response
    {
        $panier = $session->get('panier', []);

        if (empty($panier)) {
            $this->addFlash('error', 'Votre panier est vide');
            return $this->redirectToRoute('front_produit_index');
        }

        $commande = new Commande();
        $commande->setDateCreationCommande(new \DateTimeImmutable());
        $commande->setStatutCommande('En attente');
        $commande->setIdUser(1); // TODO: remplacer plus tard
        $total = 0;

        foreach ($panier as $id => $item) {
            $produit = $em->getRepository(Produit::class)->find($id);
            if (!$produit) {
                continue;
            }

            if ($produit->getStatusProduit() !== 'Disponible' || $produit->getQuantiteProduit() < $item['quantite']) {
                $this->addFlash('error', 'Problème avec ' . $produit->getNomProduit());
                return $this->redirectToRoute('front_produit_index');
            }

            $ligne = new LigneCommande();
            $ligne->setProduit($produit);
            $ligne->setQuantite_commandee($item['quantite']);
            $ligne->setCommande($commande);

            $em->persist($ligne);
            $commande->addLigneCommande($ligne);

            $total += $produit->getPrixProduit() * $item['quantite'];

            $nouveauStock = $produit->getQuantiteProduit() - $item['quantite'];
            $produit->setQuantiteProduit($nouveauStock);

            if ($nouveauStock <= 0) {
                $produit->setStatusProduit('Rupture');
            }
        }

        $commande->setMontantTotal($total);

        $em->persist($commande);
        $em->flush();

        $session->remove('panier');

        $this->addFlash('success', 'Commande validée avec succès !');
        return $this->redirectToRoute('commande_details', [
            'id' => $commande->getIdCommande()
        ]);
    }

    #[Route('/details/{id}', name: 'commande_details', methods: ['GET'])]
    public function details(Commande $commande): Response
    {
        return $this->render('commande/details.html.twig', [
            'commande' => $commande
        ]);
    }

    #[Route('/mes-commandes', name: 'mes_commandes', methods: ['GET'])]
    public function mesCommandes(EntityManagerInterface $em): Response
    {
        $commandes = $em->getRepository(Commande::class)->findAll();

        return $this->render('commande/mes_commandes.html.twig', [
            'commandes' => $commandes
        ]);
    }
}
