<?php

namespace App\APIs;

use App\Contracts\UserAPI as UserAPIContract;
use App\Traits\CurlExecutable;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class UserAPI implements UserAPIContract
{
    use CurlExecutable;

    /**
     * @throws Throwable
     */
    public function getUserByLogin(string $login, bool $withSensitiveInfo): array
    {
        $headers = [
            'APPNAME' => config('siad.check_user_app_name'),
            'APIKEY' => config('siad.check_user_api_key'),
        ];

        $response = $this->makePost(config('siad.check_user_url'), ['id' => $login], $headers);

        if (! $response['ok'] || ! $response['found']) {
            return $response;
        }

        $profile = $this->getProfile($response['UserInfo']['ID']);

        return [
            'ok' => true,
            'found' => true,
            'active' => $response['isActive'], //
            'login' => $login,
            'org_id' => $response['UserInfo']['ID'],
            'full_name' => $response['UserInfo']['DisplayName'],
            'document_id' => $withSensitiveInfo ? ($profile['pid'] ?? null) : null,
            'position_id' => $profile['job_key'] ?? null,
            'position_name' => $profile['job_key_desc'] ?? null,
            'division_id' => $profile['org_unit_m'] ?? null,
            'division_name' => $profile['org_unit_m_desc'] ?? null,
            'password_expires_in_days' => (int) str_replace('Password Remain(Day(s)): ', '', $response['message']),
            'remark' => $profile['remark'] ?? null,
        ];
    }

    /**
     * @throws Throwable
     */
    public function getUserById(int|string $id, bool $withSensitiveInfo): array
    {
        $login = $this->getStatus($id);

        if (! $login['ok'] || ! $login['found']) {
            return $login;
        }

        return $this->getUserByLogin($login['login'], $withSensitiveInfo);
    }

    /**
     * @throws Throwable
     */
    public function authenticate(string $login, string $password, bool $withSensitiveInfo): array
    {
        $headers = [
            'APPNAME' => config('siad.auth_user_app_name'),
            'APIKEY' => config('siad.auth_user_api_key'),
        ];
        $response = $this->makePost(config('siad.auth_user_url'), ['name' => $login, 'pwd' => $password], $headers);

        if (! $response['ok'] || ! $response['found'] || ($response['alt'] ?? false)) {
            return $response;
        }

        $profile = $this->getProfile($response['UserInfo']['UserData']['sapid']);

        return [
            'ok' => true, // mean user is active
            'found' => true,
            'login' => $login,
            'org_id' => $response['UserInfo']['UserData']['sapid'],
            'full_name' => $response['UserInfo']['UserData']['full_name'],
            'full_name_en' => $response['UserInfo']['UserData']['eng_name'],
            'document_id' => $withSensitiveInfo ? ($profile['pid'] ?? null) : null,
            'position_id' => $profile['job_key'] ?? null,
            'position_name' => $profile['job_key_desc'] ?? null,
            'division_id' => $profile['org_unit_m'] ?? null,
            'division_name' => $profile['org_unit_m_desc'] ?? null,
            'department_name' => $response['UserInfo']['UserData']['department'], //
            'office_name' => $response['UserInfo']['UserData']['office'], //
            'email' => $response['UserInfo']['UserData']['email'], //
            'password_expires_in_days' => $response['UserInfo']['UserData']['daysLeft'],
            'remark' => $profile['remark'] ?? null,
        ];
    }

    protected function makePost(string $url, array $data, array $headers): array
    {
        try {
            $response = Http::withHeaders($headers)
                ->post($url, $data);
        } catch (Exception $e) {
            if ($url === config('siad.auth_user_url')) {
                return $this->altAuthenticate($data['name'], $data['pwd']);
            }

            Log::error($e->getMessage());

            return [
                'ok' => false,
                'status' => 500,
                'error' => 'server',
                'message' => $e->getMessage(),
            ];
        }

        if ($response->successful()) {
            $data = $response->json() + ['ok' => true];
            if (isset($data['msg'])) {
                $data['message'] = $data['msg'];
                unset($data['msg']);
            } else {
                $data['message'] = null;
            }

            return $data;
        }

        if ($url === config('siad.auth_user_url') && in_array($response->status(), [404, 500])) {
            return $this->altAuthenticate($data['name'], $data['pwd']);
        }

        // @TODO not success has variant SHOULD cover all cases ie, response body is empty
        return [
            'ok' => false,
            'status' => $response->status(),
            'error' => $response->serverError() ? 'server' : 'client',
            'message' => $response->body(),
        ];
    }

    /**
     * @throws Throwable
     */
    protected function getProfile(int|string $id): array
    {
        $action = 'http://tempuri.org/SiITCheckUser';
        $SOAPStr = view('siit_soap.SiITCheckUser')->with(['id' => $id])->render();

        if (($response = $this->executeCurl($SOAPStr, $action, config('simrs.user_url'))) === false) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'server',
                'body' => 'Server Error',
            ];
        }

        $xml = simplexml_load_string($response);
        $namespaces = $xml->getNamespaces(true);
        $response = $xml->children($namespaces['soap'])
            ->Body
            ->children($namespaces[''])
            ->SiITCheckUserResponse
            ->SiITCheckUserResult
            ->children($namespaces['diffgr'])
            ->diffgram
            ->children()
            ->NewDataSet
            ->children()
            ->GetUsers
            ->children();

        return ((array) $response) + ['ok' => true];
    }

    protected function getStatus(string $id): array
    {
        try {
            $response = Http::withOptions(['verify' => false])
                ->post(config('siad.user_status_url'), ['employeeID' => $id]);
        } catch (Exception $e) {
            Log::error('UserAPI@getStatus: '.$e->getMessage());

            return [
                'ok' => false,
                'found' => false,
                'status' => 500,
                'error' => 'server',
                'message' => $e->getMessage(),
            ];
        }

        if ($response->status() !== 200) {
            return [
                'ok' => false,
                'found' => false,
                'message' => 'something went wrong with status code '.$response->status(),
            ];
        }

        $response = $response->json();

        if ($response === '') {
            return [
                'ok' => true,
                'found' => false,
                'message' => 'not found',
            ];
        }

        return [
            'ok' => true,
            'found' => true,
            'login' => explode('@', $response['AccountName'])[0] ,
            'status' => strtolower($response['Status']),
        ];
    }

    protected function altAuthenticate(string $login, string $password): array
    {
        $errorResponse = [
            'ok' => false,
            'found' => false,
            'status' => 500,
            'error' => 'server',
            'message' => 'alt also failed',
        ];
        try {
            $response = Http::acceptJson()->post(config('siad.alt_auth_url'), [
                'username' => $login,
                'password' => $password,
            ]);
        } catch (Exception $e) {
            Log::error('UserAPI@altAuthenticate::lab_auth | '.$e->getMessage());

            return $errorResponse;
        }

        $authenticated = $response->status() === 200
            || ($response->status() === 400 && ($response->json()['message'] ?? null) === 'ท่านไม่มีสิทธิ์ใช้งานระบบ');

        if (! $authenticated) {
            return [
                'ok' => true,
                'found' => false,
                'message' => 'not found',
            ];
        }

        if ($response->status() === 400) { // unauthorized on ALT service
            return [
                'ok' => true,
                'found' => true,
                'alt' => true,
                'login' => $login,
            ];
        }

        $userData = [];
        $userData['login'] = $login;
        $token = $response->json()['access_token'];

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->get(config('siad.alt_user_info_url'));
        } catch (Exception $e) {
            Log::error('UserAPI@altAuthenticate::lab_info | '.$e->getMessage());

            return $errorResponse;
        }

        if ($response->status() !== 200) {
            return $errorResponse;
        }

        $u1 = $response->json();
        $userData['org_id'] = $u1['sub'];
        $userData['full_name'] = $u1['unique_name'];
        $userData['email'] = $u1['email'];

        try {
            $response = Http::acceptJson()
                ->post(config('siad.alt_user_org_id_url'), ['employeeID' => $userData['org_id']]);
        } catch (Exception $e) {
            Log::error('UserAPI@altAuthenticate::checkSamAcc | '.$e->getMessage());

            return $errorResponse;
        }

        if ($response->status() !== 200) {
            return $errorResponse;
        }

        $u2 = $response->json();
        $userData['password_expires_in_days'] = $u2['pwdExpiryDay'];
        $userData['ok'] = true;
        $userData['found'] = true;
        $userData['alt'] = true;

        return $userData;
    }

    public function authenticateADFS(string $login, string $password): array
    {
        $data = [
            'grant_type' => 'password',
            'username' => $login.'@sihmis.si',
            'password' => $password,
            'client_id' => config('siad.adfs_client_id'),
            'client_secret' => config('siad.adfs_client_secret'),
        ];

        try {
            $res = Http::acceptJson()
                ->asForm()
                ->post(config('siad.adfs_auth_url'), $data)
                ->json();
        } catch (Exception $e) {
            Log::error('authenticate_adfs@'.$e->getMessage());

            return [
                'ok' => false,
                'found' => false,
                'status' => 500,
                'error' => 'server',
                'message' => $e->getMessage(),
            ];
        }

        if (array_key_exists('error', $res) && $res['error'] === 'invalid_grant') {
            return [
                'ok' => true,
                'found' => false,
                'message' => 'not found',
            ];
        }

        $payload = explode('.', $res['access_token'])[1];
        $payload = base64_decode(strtr($payload, '-_', '+/'));
        $userData = json_decode($payload, true);

        $remark = implode(' ', [
            $userData['display_name'] ?? '',
            $userData['Job Description'] ?? '',
            $userData['OrganizationUnit'] ?? '',
            $userData['Department'] ?? '',
        ]);

        $response = null;
        try {
            $response = Http::acceptJson()
                ->post(config('siad.alt_user_org_id_url'), ['employeeID' => $userData['employee_id']]);
        } catch (Exception $e) {
            Log::error('UserAPI@authenticateADFS::checkSamAcc | '.$e->getMessage());
        }

        $passwordExpiredInDays = null;

        if ($response && $response->status() === 200) {
            $passwordExpiredInDays = $response->json()['pwdExpiryDay'];
        }

        return [
            'ok' => true,
            'found' => true,
            'login' => $login,
            'org_id' => $userData['employee_id'] ?? null,
            'full_name' => $userData['display_name'] ?? null,
            'full_name_en' => $userData['commonname'] ?? null,
            'document_id' => null,
            'position_id' => $userData['Position'] ?? null,
            'position_name' => $userData['Job Description'] ?? null,
            'division_id' => null,
            'division_name' => $userData['OrganizationUnit'] ?? null,
            'department_name' => $userData['Department'] ?? null,
            'office_name' => $userData['OrganizationUnit'] ?? null,
            'email' => $userData['email'] ?? null,
            'password_expires_in_days' => $passwordExpiredInDays,
            'remark' => trim($remark),
        ];
    }
}
