<?php
namespace App\Controller\Visitor\Registration;



use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Service\SendEmailService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'visitor.registration.register')]
    public function register(Request $request,
     UserPasswordHasherInterface $userPasswordHasher,
     EntityManagerInterface $entityManager,
     TokenGeneratorInterface $tokenGenerator,
     SendEmailService $sendEmailService

    ): Response
    {

        if ($this->getUser()) {
            return $this->redirectToRoute('visitor.welcome.index');
        }
        
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Génaration du jeton de sécurité util pour la vérification du compte par email
            $tokenGenerated = $tokenGenerator->generateToken();
            $user->setTokenForEmailVerification($tokenGenerated);


            // Génération de la date limite pour la vérification du compte par email 
            $deadline = (new \DateTimeImmutable('now'))->add(new \DateInterval('P1D'));
            $user->setDeadLineForEmailVerification($deadline);


            // encode the plain password
            $passwordHashed = $userPasswordHasher->hashPassword($user,$form->get('password')->getData());
            $user->setPassword($passwordHashed);

            // Le manager des entités prépare la requête
            $entityManager->persist($user);

            // Puis, il l'exécute
            $entityManager->flush();
            
            // do anything else you need here, like send an email
            $sendEmailService->send([
                "sender_email"      => "medecine-du-monde@gmail.com",
                "sender_name"       => "Jean Dupont",
                "recipient_email" => $user->getEmail(),
                "subject"         => "Vérification de votre compte sur le blog de Jean Dupont",
                "html_template"   => "email/email_verification.html.twig",
                "context"         => [
                    "user_id"                          => $user->getId(),
                    "token_for_email_verification"     => $user->getTokenForEmailVerification(),
                    "dead_line_for_email_verification" => $user->getDeadLineForEmailVerification()->format('d/m/Y à H:i:s')
                ]
            ]);

            return $this->redirectToRoute('visitor.registration.waiting_for_email_verification');
        }

        return $this->render('pages/visitor/registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }

    #[Route('/register/waiting-for-email-verification', name: 'visitor.registration.waiting_for_email_verification')]
    public function waitingForEmailVerification() : Response
    {
      return  $this->render("pages/visitor/registration/waiting_for_email_verification.html.twig");
    }


    #[Route('/register/email-verification/{id<\d+>}/{token}', name: 'visitor.registration.email_verification')]
    public function emailVerification(User $user, string $token, UserRepository $userRepository) : Response
    {

        // Si l'utilisateur n'existe pas, on refuse l'accès
        if (! $user) {

            throw new AccessDeniedException();
        }

        /* 
         * Si l'utilisateur a déjà vérifier son compte, on le redirige vers la page de connexion de connexion
         * avec un message flash lui expliquant que son compte est déjà vérifié et qu'il peut maintenant se connecter
         */
        if ($user->isIsVerified() ){
            
            $this->addFlash("warning", "Votre compte a déjà été vérifié! Vous pouvez vous connectez.");
            return $this->redirectToRoute("visitor.authentication.login");
        }

        /**
         * Si le token récupéré depuis l'email de l'utilisateur est vide
         * Ou le token qui a été inséré en tant que valeur de la propriété $user->tokenForEmailVerification est nulle
         * Ou le token récupéré ne contient pas la même valeur que le token de la propriété 
         * $user->tokenForEmailVerification
         */
        if( empty($token) || ($user->getTokenForEmailVerification() == "") || ($user->getTokenForEmailVerification() === null ) || ($token !== $user->getTokenForEmailVerification()) ) {
            
            throw new AccessDeniedException();
        }

        /**
         * Si l'instant durant lequel l'i-tuilisateur vérifie son compte est strictement supérieur
         * à la date limite de validation du compte, c'est que la date limite a expiré.
         */
        if ( (new \DateTimeImmutable('now') > $user->getDeadLineForEmailVerification() )  ){

            $deadline = $user->getDeadLineForEmailVerification()->format("d/m/Y à H:i:s");
            $userRepository->remove($user, true);
            throw new CustomUserMessageAccountStatusException("Votre delais de vérification du compte a expérié le : $deadline! Veuillez vous réinscrire.");
        }

        // On vérifie le compte
        $user->setIsVerified(true);
        

        // Initialisation de la date de vérification du compte
        $user->setVerifiedAt(new \DateTimeImmutable('now'));


        // On retir le jeton de sécurité
        $user->setTokenForEmailVerification('');


        // Requête de modification de l'entité $user
        $userRepository->save($user, true);

        
        // Génération du message flash
        $this->addFlash('success', "Votre compte a bien été vérifié! Vous pouvez vous connecter.");


        // Redirection vers la page d'accueil
        return $this->redirectToRoute("visitor.authentication.login");


    }

}
