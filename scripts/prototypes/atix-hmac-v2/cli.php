<?php
/** LOCAL stdin/stdout harness, never an HTTP endpoint. */
declare(strict_types=1);
require __DIR__.'/Prototype.php';
use Famedic\LocalPrototype\AtixV2\{ProofRequest, Verifier, SqliteNonces, BindingCheck, Denied};
try {
    $input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $r = $input['request'];
    $known = ['audience','key_id','method','path','query','timestamp','nonce','channel','pat','idempotency_key','body_base64'];
    if (array_diff(array_keys($r), $known) || array_diff($known, array_keys($r))) { throw new Denied('V2_MALFORMED'); }
    $body = base64_decode($r['body_base64'], true);
    if ($body === false) { throw new Denied('V2_MALFORMED'); }
    $request = new ProofRequest($r['audience'],$r['key_id'],$r['method'],$r['path'],$r['query'],
        $r['timestamp'],$r['nonce'],$r['channel'],$r['pat'],$r['idempotency_key'],$body);
    try { $store = new SqliteNonces($input['store'], ($input['storage_mode'] ?? '') === 'query_only'); }
    catch (\PDOException) { throw new Denied('V2_STORAGE_UNAVAILABLE'); }
    $proof = (new Verifier($input['registry'],$store,$input['tolerance'] ?? 300,$input['margin'] ?? 5))
        ->verify($request,$input['signature'],$input['target_audience'],$input['profile'],$input['now'],$input['require_pat'] ?? true);
    $matches = BindingCheck::matches($proof,$input['binding'] ?? null,$input['user_id'] ?? 0,$input['now']);
    $output = ['result'=>'PROOF_ACCEPTED','integration'=>$proof->integration,'kind'=>$proof->kind,
        'canonical_base64'=>base64_encode($request->canonical()),'canonical_sha256'=>$proof->requestDigest,
        'nonce_expires_at'=>$proof->nonceExpiresAt,'binding_fixture_matches'=>$matches,
        'patient_authenticated'=>false,'payment_permission_granted'=>false];
    if ($input['check_payment_policy'] ?? false) {
        require dirname(__DIR__,3).'/vendor/autoload.php';
        $user = new \App\Models\User; $user->setRawAttributes(['id'=>$input['user_id'] ?? 1]);
        $token = new \Laravel\Sanctum\PersonalAccessToken;
        $token->id = 2; $token->abilities = $input['abilities'] ?? [];
        $identity = \App\Services\Api\V1\Auth\ConsumerIdentityResolution::rejected();
        if ($matches) {
            $context = new \App\Services\Api\V1\Auth\ConsumerAuthorizationContext(
                $proof->integration,$proof->kind,$proof->channel,'synthetic-delegation',
                (int)$user->id,2,$input['consumer_capabilities'] ?? [],$input['delegation_capabilities'] ?? [],
                $input['binding']['expires_at'],true,true,true,true);
            $identity = \App\Services\Api\V1\Auth\ConsumerIdentityResolution::verified($context);
        }
        $output['existing_pure_payment_policy_denial'] = (new \App\Services\Api\V1\Auth\PaymentLinkAccessPolicy)
            ->denial($user,$token,$identity,$input['now']);
        // No action, authorization adapter, authenticated patient or link is created.
    }
    echo json_encode($output, JSON_THROW_ON_ERROR)."\n";
} catch (Denied $e) {
    echo json_encode(['result'=>'DENIED','code'=>$e->reason])."\n";
} catch (\Throwable $e) {
    // Do not echo input, credentials or exception messages.
    echo json_encode(['result'=>'HARNESS_ERROR','class'=>get_class($e)])."\n";
    exit(2);
}
