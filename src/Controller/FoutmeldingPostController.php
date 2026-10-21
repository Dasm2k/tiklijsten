<?php

namespace App\Controller;

use App\Form\FoutmeldingType;
use App\Service\FoutmeldingIndienenService;
use App\Service\PostFoutmeldingToegang;
use App\Service\TikFout;
use App\Service\TikService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

#[Route('/foutmeldingen/post')]
#[IsGranted('ROLE_POST')]
final class FoutmeldingPostController extends BaseController
{
    #[Route('/aanmelden', name: 'app_fout_post_aanmelden', methods: ['GET', 'POST'])]
    public function aanmelden(Request $request, TikService $tik, PostFoutmeldingToegang $toegang, EntityManagerInterface $em): Response
    {
        $this->moduleActief();
        $toegang->afmelden($request->getSession());
        $form = $this->createFormBuilder(null, ['csrf_token_id' => 'post_fout_aanmelden'])
            ->add('tiknummer', TextType::class, [
                'label' => 'Tiknummer',
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'username', 'autofocus' => true],
                'constraints' => [new Assert\NotBlank(), new Assert\Regex('/^[0-9]+$/', message: 'Geef je tiknummer in (enkel cijfers).')],
            ])
            ->add('code', PasswordType::class, [
                'label' => 'Code',
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'current-password'],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            try {
                $melder = $tik->controleerVoorFoutmelding($this->afdeling(), $data['tiknummer'], $data['code']);
                // Bewaar een eventuele upgrade van een geïmporteerde MD5-code naar bcrypt.
                $em->flush();
                $request->getSession()->migrate(true);
                $toegang->aanmelden($request->getSession(), $this->persoon(), $melder);

                return $this->redirectToRoute('app_fout_post_nieuw', [], Response::HTTP_SEE_OTHER);
            } catch (TikFout $e) {
                $form->get('code')->addError(new FormError($e->getMessage()));
            }
        }

        $response = $this->render('fout/post_aanmelden.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/nieuw', name: 'app_fout_post_nieuw', methods: ['GET', 'POST'])]
    public function nieuw(Request $request, PostFoutmeldingToegang $toegang, FoutmeldingIndienenService $service): Response
    {
        $this->moduleActief();
        $melder = $toegang->melder($request->getSession(), $this->persoon());
        if (!$melder) {
            $this->addFlash('info', 'Meld je aan met je persoonlijke tiknummer en code om een foutmelding in te leggen.');

            return $this->redirectToRoute('app_fout_post_aanmelden', [], Response::HTTP_SEE_OTHER);
        }

        $form = $this->createForm(FoutmeldingType::class, null, ['afdeling' => $this->afdeling(), 'email_vragen' => !$melder->getEmail()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $fout = $service->indienen($melder, $form->getData());
            $toegang->afmelden($request->getSession());
            $this->addFlash('success', $fout ? 'Je foutmelding is verstuurd. Bedankt!' : 'De melding van het kadaver is doorgestuurd.');

            return $this->redirectToRoute('app_tik_index', [], Response::HTTP_SEE_OTHER);
        }

        $response = $this->render('fout/nieuw.html.twig', [
            'form' => $form,
            'melder' => $melder,
            'post_melding' => true,
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/afmelden', name: 'app_fout_post_afmelden', methods: ['POST'])]
    public function afmelden(Request $request, PostFoutmeldingToegang $toegang): Response
    {
        if (!$this->csrfOk($request, 'post_fout_afmelden')) {
            throw $this->createAccessDeniedException('Het formulier is verlopen.');
        }
        $toegang->afmelden($request->getSession());

        return $this->redirectToRoute('app_tik_index', [], Response::HTTP_SEE_OTHER);
    }

    private function moduleActief(): void
    {
        if (!$this->afdeling()->isFoutmeldingenActief()) {
            throw $this->createNotFoundException();
        }
    }
}
