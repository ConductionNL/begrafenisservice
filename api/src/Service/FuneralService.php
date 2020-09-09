<?php
namespace App\Service;

use App\Entity\Webhook;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use DateTime;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\KeyManagement\KeyConverter\RSAKey;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Easy\Build;
use Jose\Easy\JWT;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FuneralService
{
    private $commonGroundService;
    private $params;
    public function __construct(CommonGroundService $commonGroundService, ParameterBagInterface $params){
        $this->commonGroundService = $commonGroundService;
        $this->params = $params;
    }

    public function handle(Webhook $webhook){

        $results = [];
        $results[] = $this->sendConfirmation($webhook);
        $webhook->setResult($results);
        $this->em->persist($webhook);
        $this->em->flush();
    }

    public function sendConfirmation($webhook){
        $request = $this->commonGroundService->getResource($webhook->getRequest());
        $requestType = $this->commonGroundService->getResource($request['requestType']);

        $attachments = [];
        if(key_exists('templates', $requestType)){
            foreach($requestType['templates'] as $template){
                $attachment = [];
                $attachment['uri'] = $template['uri'];
                $attachment['mime'] = $template['type'];
                $attachment['name'] = $template['name'];
                $attachment['resources'] = ['request'=>$request['@id']];
                $result = $this->commonGroundService->createResource($attachment, ['component'=>'bs', 'type'=>'attachments']);
                if(is_array('result')){
                    $attachments[] = $result['@id'];
                }
            }
        }
        if(key_exists('order', $request) && $request['order'] != null){
            $application = $this->commonGroundService->getResource(['component' => 'wrc', 'type' => 'applications', 'id' => getenv('APP_ID')]);
            $order = $this->commonGroundService->getResource($request['order']);
            $orderTemplate = $this->commonGroundService->getResource($application['defaultConfiguration']['configuration']['orderTemplate']);
            $attachment = [];
            $attachment['uri'] = $orderTemplate['@id'];
            $attachment['mime'] = 'application/pdf';
            $attachment['name'] = "{$order['reference']}.pdf";
            $attachment['resources'] = ['resource'=>$order['@id']];
            $attachments[] = $attachment;
            if(key_exists('invoice', $order) && $order['invoice'] != null){
                $invoice = $this->commonGroundService->getResource($order['invoice']);
                $invoiceTemplate = $this->commonGroundService->getResource($application['defaultConfiguration']['configuration']['invoiceTemplate']);
                $attachment = [];
                $attachment['uri'] = $invoiceTemplate['@id'];
                $attachment['mime'] = 'application/pdf';
                $attachment['name'] = "{$invoice['name']}.pdf";
                $attachment['resources'] = ['resource'=>$invoice['@id']];
                $attachments[] = $attachment;
            }
        }
        $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-bevestiging"])['@id'];

        $message = [];
        $message['service'] = $this->commonGroundService->getResourceList(['component'=>'bs', 'type'=>'services'], "?type=mailer&organization={$request['organization']}")['hydra:member'][0]['@id'];
        $message['status'] = 'queued';
        $organization = $this->commonGroundService->getResource($request['organization']);

        if ($organization['contact']) {
            $message['sender'] = $organization['contact'];
        }
        if(key_exists('contactpersoon', $request['properties'])){
            $message['reciever'] = $request['properties']['contactpersoon'];
            if (!key_exists('sender', $message)) {
                $message['sender'] = $message['reciever'];
            }
        }
        $message['data'] = ['resource'=>$request, 'sender'=>$organization, 'receiver'=>$this->commonGroundService->getResource($message['reciever'])];
        $message['content'] = $content;
        $message['attachments'] = $attachments;

        $result = $this->commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages'])['@id'];

        return $result;
    }

}
