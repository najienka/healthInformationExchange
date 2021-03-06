<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesResources;

use Config;
use DB;
use Google_Client;
use Mail;
use Swift_Mailer;
use Swift_SmtpTransport;

class Controller extends BaseController
{
    use AuthorizesRequests, AuthorizesResources, DispatchesJobs, ValidatesRequests;

    protected function gen_uuid()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    protected function gen_secret()
    {
        $length = 512;
        $val = '';
        for ($i = 0; $i < $length; $i++) {
            $val .= rand(0, 9);
        }
        $fp = fopen('/dev/urandom', 'rb');
        $val = fread($fp, 32);
        fclose($fp);
        $val .= uniqid(mt_rand(), true);
        $hash = hash('sha512', $val, true);
        $result = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
        return $result;
    }

    protected function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected function send_mail($template, $data_message, $subject, $to)
    {
        $google_client = DB::table('oauth_rp')->where('type', '=', 'google')->first();
        $google = new Google_Client();
        $google->setClientID($google_client->client_id);
        $google->setClientSecret($google_client->client_secret);
        $google->refreshToken($google_client->refresh_token);
        $credentials = $google->getAccessToken();
        $username = $google_client->smtp_username;
        $password = $credentials['access_token'];
        $config = [
            'mail.driver' => 'smtp',
            'mail.host' => 'smtp.gmail.com',
            'mail.port' => 465,
            'mail.from' => ['address' => null, 'name' => null],
            'mail.encryption' => 'ssl',
            'mail.username' => $username,
            'mail.password' => $password,
            'mail.sendmail' => '/usr/sbin/sendmail -bs'
        ];
        config($config);
        extract(Config::get('mail'));
        $transport = Swift_SmtpTransport::newInstance($host, $port, 'ssl');
        $transport->setAuthMode('XOAUTH2');
        if (isset($encryption)) {
            $transport->setEncryption($encryption);
        }
        if (isset($username)) {
            $transport->setUsername($username);
            $transport->setPassword($password);
        }
        $owner = DB::table('owner')->first();
        Mail::setSwiftMailer(new Swift_Mailer($transport));
        Mail::send($template, $data_message, function ($message) use ($to, $subject, $owner) {
            $message->to($to)
                ->from($owner->email, $owner->firstname . ' ' . $owner->lastname)
                ->subject($subject);
        });
        return "E-mail sent.";
    }

    protected function group_policy($client_id, $types, $action)
    {
        // $types is an array of claims
        $default_policy_type = [
            'login_direct',
            'login_md_nosh',
            'any_npi',
            'login_google'
        ];
        // Create default policy claims if they don't exist
        foreach ($default_policy_type as $default_claim) {
            $claims = DB::table('claim')->where('claim_value', '=', $default_claim)->first();
            if (!$claims) {
                $claims_data = [
                    'name' => 'Group',
                    'claim_value' => $default_claim
                ];
                DB::table('claim')->insert($claims_data);
            }
        }
        // Find all existing default polices for the resource server
        $default_policies_old_array = [];
        $resource_set_id_array = [];
        $policies_array = [];
        $resource_sets = DB::table('resource_set')->where('client_id', '=', $client_id)->get();
        if ($resource_sets) {
            foreach ($resource_sets as $resource_set) {
                $resource_set_id_array[] = $resource_set->resource_set_id;
                $policies = DB::table('policy')->where('resource_set_id', '=', $resource_set->resource_set_id)->get();
                if ($policies) {
                    foreach ($policies as $policy) {
                        $policies_array[] = $policy->policy_id;
                        $query1 = DB::table('claim_to_policy')->where('policy_id', '=', $policy->policy_id)->get();
                        if ($query1) {
                            foreach ($query1 as $row1) {
                                $query2 = DB::table('claim')->where('claim_id', '=', $row1->claim_id)->first();
                                if ($query2) {
                                    if (in_array($query2->claim_value, $default_policy_type)) {
                                        $default_policies_old_array[] = $policy->policy_id;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        // Remove all existing default policy scopes to refresh them, delete them all if action is to delete
        if (count($default_policies_old_array) > 0) {
            foreach ($default_policies_old_array as $old_policy_id) {
                DB::table('policy_scopes')->where('policy_id', '=', $old_policy_id)->delete();
                DB::table('claim_to_policy')->where('policy_id', '=', $old_policy_id)->delete();
                if ($action == 'delete') {
                    DB::table('policy')->where('policy_id', '=', $old_policy_id)->delete();
                }
            }
        }
        if ($action !== 'delete') {
            // Identify resource sets without policies and create new policies
            // Get all resource set scopes to default policies
            if (count($resource_set_id_array) > 0) {
                foreach ($resource_set_id_array as $resource_set_id) {
                    $query3 = DB::table('policy')->where('resource_set_id', '=', $resource_set_id)->first();
                    if ($query3) {
                        // Check if there is an existing policy with this resource set and attach all scopes these policies
                        $policies1 = DB::table('policy')->where('resource_set_id', '=', $resource_set_id)->get();
                        if ($policies1) {
                            foreach ($policies1 as $policy1) {
                                if (in_array($policy1->policy_id, $default_policies_old_array)) {
                                    foreach ($types as $type) {
                                        $query4 = DB::table('claim')->where('claim_value', '=', $type)->first();
                                        $data1 = [
                                          'claim_id' => $query4->claim_id,
                                          'policy_id' => $policy1->policy_id
                                        ];
                                        DB::table('claim_to_policy')->insert($data1);
                                    }
                                    $resource_set_scopes = DB::table('resource_set_scopes')->where('resource_set_id', '=', $resource_set_id)->get();
                                    if ($resource_set_scopes) {
                                        foreach ($resource_set_scopes as $resource_set_scope) {
                                            $data2 = [
                                                'policy_id' => $policy1->policy_id,
                                                'scope' => $resource_set_scope->scope
                                            ];
                                            DB::table('policy_scopes')->insert($data2);
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        // Needs new policy
                        $data3['resource_set_id'] = $resource_set_id;
                        $policy_id = DB::table('policy')->insertGetId($data3);
                        foreach ($types as $type1) {
                            $query5 = DB::table('claim')->where('claim_value', '=', $type1)->first();
                            $data4 = [
                              'claim_id' => $query5->claim_id,
                              'policy_id' => $policy_id
                            ];
                            DB::table('claim_to_policy')->insert($data4);
                        }
                        $resource_set_scopes1 = DB::table('resource_set_scopes')->where('resource_set_id', '=', $resource_set_id)->get();
                        if ($resource_set_scopes1) {
                            foreach ($resource_set_scopes1 as $resource_set_scope1) {
                                $data5 = [
                                    'policy_id' => $policy_id,
                                    'scope' => $resource_set_scope1->scope
                                ];
                                DB::table('policy_scopes')->insert($data5);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
    * SMS notifcation with TextBelt
    *
    * @return Response
    */
    protected function textbelt($number, $message)
    {
        $url = 'http://textbelt.com/text';
        $message = http_build_query([
            'number' => $number,
            'message' => $message
        ]);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }
}
