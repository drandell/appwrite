<?php

use Utopia\App;
use Utopia\Config\Config;
use Utopia\Domains\Domain;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Network\Validator\CNAME;

require_once __DIR__.'/../init.php';

cli_set_process_title('Certificates V1 Worker');

echo APP_NAME.' certificates worker v1 has started';

class CertificatesV1
{
    public $args = [];

    public function setUp()
    {
    }

    public function perform()
    {
        global $request, $consoleDB;

        /**
         * 1. Get new domain document - DONE
         *  1.1. Validate domain is valid, public suffix is known and CNAME records are verified - DONE
         * 2. Check if a certificate already exists - DONE
         * 3. Check if certificate is about to expire, if not - skip it
         *  3.1. Create / renew certificate
         *  3.2. Update loadblancer
         *  3.3. Update database (domains, change date, expiry)
         *  3.4. Set retry on failure
         *  3.5. Schedule to renew certificate in 60 days
         */

        Authorization::disable();

        // Args
        $document = $this->args['document'];
        $domain = $this->args['domain'];

        // Validation Args
        $validateTarget = (isset($this->args['validateTarget'])) ? $this->args['validateTarget'] : true;
        $validateCNAME = (isset($this->args['validateCNAME'])) ? $this->args['validateCNAME'] : true;
        
        // Options
        $domain = new Domain((!empty($domain)) ? $domain : '');
        $expiry = 60 * 60 * 24 * 30 * 2; // 60 days
        $safety = 60 * 60; // 1 hour
        $renew  = (time() + $expiry);

        if(empty($domain->get())) {
            throw new Exception('Missing domain');
        }

        if(!$domain->isKnown() || $domain->isTest()) {
            throw new Exception('Unkown public suffix for domain');
        }

        if($validateTarget) {
            $target = new Domain($request->getServer('_APP_DOMAIN_TARGET', ''));
    
            if(!$target->isKnown() || $target->isTest()) {
                throw new Exception('Unreachable CNAME target ('.$target->get().'), plesse use a domain with a public suffix.');
            }
        }

        if($validateCNAME) {
            $validator = new CNAME($target->get()); // Verify Domain with DNS records
    
            if(!$validator->isValid($domain->get())) {
                throw new Exception('Failed to verify domain DNS records');
            }
        }

        $certificate = $consoleDB->getCollection([
            'limit' => 1,
            'offset' => 0,
            'orderField' => 'id',
            'orderType' => 'ASC',
            'orderCast' => 'string',
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_CERTIFICATES,
                'domain='.$domain->get(),
            ],
            'first' => true,
        ]);

        // $condition = ($certificate
        //     && $certificate instanceof Document
        //     && isset($certificate['issueDate'])
        //     && (($certificate['issueDate'] + ($expiry)) > time())) ? 'true' : 'false';

        // throw new Exception('cert issued at'.date('d.m.Y H:i', $certificate['issueDate']).' | renew date is: '.date('d.m.Y H:i', ($certificate['issueDate'] + ($expiry))).' | condition is '.$condition);

        $certificate = (!empty($certificate) && $certificate instanceof $certificate) ? $certificate->getArrayCopy() : [];

        if(!empty($certificate)
            && isset($certificate['issueDate'])
            && (($certificate['issueDate'] + ($expiry)) > time())) { // Check last issue time
                throw new Exception('Renew isn\'t required');
        }

        $staging = (Config::getParam('env') === App::ENV_TYPE_PRODUCTION) ? '' : ' --dry-run';

        $response = shell_exec("certbot certonly --webroot --noninteractive --agree-tos{$staging} --email security@appwrite.io \
            -w ".APP_STORAGE_CERTIFICATES." \
            -d {$domain->get()}");

        if(!$response) {
            throw new Exception('Failed to issue a certificate');
        }

        $path = APP_STORAGE_CERTIFICATES.'/'.$domain->get();

        if(!is_readable($path)) {
            if (!mkdir($path, 0755, true)) {
                throw new Exception('Failed to create path...');
            }
        }
        
        if(!@rename('/etc/letsencrypt/live/'.$domain->get().'/cert.pem', APP_STORAGE_CERTIFICATES.'/'.$domain->get().'/cert.pem')) {
            throw new Exception('Failed to rename certificate cert.pem: '.json_encode($response));
        }

        if(!@rename('/etc/letsencrypt/live/'.$domain->get().'/chain.pem', APP_STORAGE_CERTIFICATES.'/'.$domain->get().'/chain.pem')) {
            throw new Exception('Failed to rename certificate chain.pem: '.json_encode($response));
        }

        if(!@rename('/etc/letsencrypt/live/'.$domain->get().'/fullchain.pem', APP_STORAGE_CERTIFICATES.'/'.$domain->get().'/fullchain.pem')) {
            throw new Exception('Failed to rename certificate fullchain.pem: '.json_encode($response));
        }

        if(!@rename('/etc/letsencrypt/live/'.$domain->get().'/privkey.pem', APP_STORAGE_CERTIFICATES.'/'.$domain->get().'/privkey.pem')) {
            throw new Exception('Failed to rename certificate privkey.pem: '.json_encode($response));
        }

        $certificate = array_merge($certificate, [
            '$collection' => Database::SYSTEM_COLLECTION_CERTIFICATES,
            '$permissions' => [
                'read' => [],
                'write' => [],
            ],
            'domain' => $domain->get(),
            'issueDate' => time(),
            'renewDate' => $renew,
            'attempts' => 0,
            'log' => json_encode($response),
        ]);

        $certificate = $consoleDB->createDocument($certificate);

        if(!$certificate) {
            throw new Exception('Failed saving certificate to DB');
        }

        if(!empty($document)) {
            $document = array_merge($document, [
                'updated' => time(),
                'certificateId' => $certificate->getId(),
            ]);
    
            $document = $consoleDB->updateDocument($document);
    
            if(!$document) {
                throw new Exception('Failed saving domain to DB');
            }
        }
        
        $config = 
"tls:
  certificates:
    - certFile: /storage/certificates/{$domain->get()}/fullchain.pem
      keyFile: /storage/certificates/{$domain->get()}/privkey.pem";

        if(!file_put_contents(APP_STORAGE_CONFIG.'/'.$domain->get().'.yml', $config)) {
            throw new Exception('Failed to save SSL configuration');
        }

        ResqueScheduler::enqueueAt($renew + $safety, 'v1-certificates', 'CertificatesV1', [
            'document' => [],
            'domain' => $domain->get(),
            'validateTarget' => $validateTarget,
            'validateCNAME' => $validateCNAME,
        ]);  // Async task rescheduale

        Authorization::reset();
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }
}
