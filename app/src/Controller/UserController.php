<?php

namespace App\Controller;

use App\Service\LdapService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;

class UserController extends AbstractController
{
    #[Route('/api/user/search', name: 'user_search', methods: ['GET'])]
    /**
     * @OA\Get(
     *     path="/api/user/search",
     *     summary="Finde Benutzer anhand von samAccountName oder Email",
     *     @OA\Parameter(name="samAccountName", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="email", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="User gefunden"),
     *     @OA\Response(response=400, description="Fehlender Parameter"),
     *     @OA\Response(response=404, description="User nicht gefunden")
     * )
     */
    public function search(Request $request, LdapService $ldapService): JsonResponse
    {
        $sam = $request->query->get('samAccountName');
        $mail = $request->query->get('email');

        if (!$sam && !$mail) {
            return $this->json(['error' => 'Parameter "samAccountName" oder "email" erforderlich'], 400);
        }

        try {
            $user = $ldapService->findUser($sam, $mail);
        } catch (\Exception $e) {
            return $this->json(['error' => 'LDAP-Fehler: ' . $e->getMessage()], 500);
        }

        if (!$user) {
            return $this->json(['success' => false, 'error' => 'Benutzer nicht gefunden'], 200);
        }

        return $this->json(['success' => true, 'data' => $user]);
    }

    #[Route('/api/user/{samAccountName}/unlock', name: 'user_unlock', methods: ['POST'])]
    /**
     * @OA\Post(
     *     path="/api/user/{samAccountName}/unlock",
     *     summary="Benutzerkonto entsperren",
     *     @OA\Parameter(name="samAccountName", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Benutzer entsperrt"),
     *     @OA\Response(response=404, description="Benutzer nicht gefunden"),
     *     @OA\Response(response=500, description="Fehler beim Entsperren")
     * )
     */
    public function unlock(string $samAccountName, LdapService $ldapService): JsonResponse
    {
        try {
            $dn = $ldapService->getDnBySamAccountName($samAccountName);

            if (!$dn) {
                return $this->json(['success' => false, 'error' => 'Benutzer nicht gefunden'], 200);
            }

            $ldapService->unlockUserByDn($dn);

            return $this->json(['success' => true, 'message' => 'Benutzer wurde entsperrt.']);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Fehler beim Entsperren: ' . $e->getMessage()], 200);
        }
    }


    #[Route('/api/user/{samAccountName}/disable', name: 'user_disable', methods: ['POST'])]
    /**
     * @OA\Post(
     *     path="/api/user/{samAccountName}/disable",
     *     summary="Benutzer deaktivieren",
     *     @OA\Parameter(
     *         name="samAccountName",
     *         in="path",
     *         required=true,
     *         description="sAMAccountName des Benutzers",
     *         @OA\Schema(type="string", example="jdoe")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Antwort mit success true/false",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="error", type="string")
     *         )
     *     )
     * )
     */
    public function disable(string $samAccountName, LdapService $ldapService): JsonResponse
    {
        try {
            $dn = $ldapService->getDnBySamAccountName($samAccountName);

            if (!$dn) {
                return $this->json([
                    'success' => false,
                    'error' => 'Benutzer nicht gefunden'
                ]);
            }

            $ldapService->disableUserByDn($dn);

            return $this->json([
                'success' => true,
                'message' => 'Benutzer wurde deaktiviert.'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Fehler beim Deaktivieren: ' . $e->getMessage()
            ]);
        }
    }


    #[Route('/api/user/{samAccountName}/enable', name: 'user_enable', methods: ['POST'])]
    /**
     * @OA\Post(
     *     path="/api/user/{samAccountName}/enable",
     *     summary="Benutzer aktivieren",
     *     @OA\Parameter(
     *         name="samAccountName",
     *         in="path",
     *         required=true,
     *         description="sAMAccountName des Benutzers",
     *         @OA\Schema(type="string", example="jdoe")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Antwort mit success true/false",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="error", type="string")
     *         )
     *     )
     * )
     */
    public function enable(string $samAccountName, LdapService $ldapService): JsonResponse
    {
        try {
            $dn = $ldapService->getDnBySamAccountName($samAccountName);

            if (!$dn) {
                return $this->json([
                    'success' => false,
                    'error' => 'Benutzer nicht gefunden'
                ]);
            }

            $ldapService->enableUserByDn($dn);

            return $this->json([
                'success' => true,
                'message' => 'Benutzer wurde aktiviert.'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Fehler beim Aktivieren: ' . $e->getMessage()
            ]);
        }
    }

    #[Route('/api/user/{samAccountName}/password', name: 'user_password_reset', methods: ['POST'])]
    /**
     * @OA\Post(
     *     path="/api/user/{samAccountName}/password",
     *     summary="Passwort eines Benutzers zurücksetzen",
     *     @OA\Parameter(name="samAccountName", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"password"},
     *             @OA\Property(property="password", type="string", example="Geheimes123!")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Antwort mit success true/false")
     * )
     */
    public function resetPassword(string $samAccountName, Request $request, LdapService $ldapService): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $password = $data['password'] ?? null;

        if (!$password) {
            return $this->json([
                'success' => false,
                'error' => 'Passwort fehlt im Request-Body'
            ]);
        }

        try {
            $dn = $ldapService->getDnBySamAccountName($samAccountName);

            if (!$dn) {
                return $this->json([
                    'success' => false,
                    'error' => 'Benutzer nicht gefunden'
                ]);
            }

            $ldapService->resetPasswordByDn($dn, $password);

            return $this->json([
                'success' => true,
                'message' => 'Passwort wurde zurückgesetzt.'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Fehler beim Zurücksetzen: ' . $e->getMessage()
            ]);
        }
    }

}
