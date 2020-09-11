<?php
namespace App\Service;

use App\Entity\WebHook;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
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
    private $em;
    public function __construct(CommonGroundService $commonGroundService, ParameterBagInterface $params, EntityManagerInterface $em){
        $this->commonGroundService = $commonGroundService;
        $this->params = $params;
        $this->em = $em;
    }

    public function handle(WebHook $webhook){

        $results = [];
        $request = $this->commonGroundService->getResource($webhook->getRequest());

        if($request['status'] == 'submitted'){
            $results[] = $this->sendConfirmation($webhook, $request);
        }
        elseif(
            $request['status'] == 'inProgress' ||
            $request['status'] == 'processed' ||
            $request['status'] == 'reject'
        ){
            $this->statusChange($webhook, $request);
        }
        $results = array_merge($results, $this->sendReservation($webhook, $request));
        $webhook->setResult($results);
        $this->em->persist($webhook);
        $this->em->flush();

        return $webhook;
    }

    public function createMessage(array $request, $content, $receiver, $attachments = null){
        $application = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}"]);
        if(key_exists('@id', $application['organization'])){
            $serviceOrganization = $application['organization']['@id'];
        } else {
            $serviceOrganization = $request['organization'];
        }

        $message = [];
        $message['service'] = $this->commonGroundService->getResourceList(['component'=>'bs', 'type'=>'services'], "?type=mailer&organization=$serviceOrganization")['hydra:member'][0]['@id'];
        $message['status'] = 'queued';
        $organization = $this->commonGroundService->getResource($request['organization']);

        if ($organization['contact']) {
            $message['sender'] = $organization['contact'];
        }
        $message['reciever'] = $receiver;
        if (!key_exists('sender', $message)) {
            $message['sender'] = $receiver;
        }

        $message['data'] = ['resource'=>$request, 'sender'=>$organization, 'receiver'=>$this->commonGroundService->getResource($message['reciever'])];
        $message['content'] = $content;
        if($attachments){
            $message['attachments'] = $attachments;
        }

        return $message;
    }

    public function sendReservation($webhook, $request){
        $users = $this->commonGroundService->getResource(['component'=>'uc', 'type'=>'groups', 'id'=>"e71a21e5-2bfe-4515-8d65-c3d99a9fd893"])['users'];
        $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-reservering"])['@id'];

        $results = [];
        foreach($users as $user){
            if($user['person'] && $user['organization'] == $request['organization']){
                $message = $this->createMessage($request, $content, $user['person']);
                $results[] = $this->commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages'])['@id'];
            }
        }
        return $results;
    }

    public function sendConfirmation($webhook, $request){
        $requestType = $this->commonGroundService->getResource($request['requestType']);

        $attachments = [];
        if(key_exists('templates', $requestType)){
            foreach($requestType['templates'] as $template){
                $attachment = [];
                $attachment['uri'] = $template['uri'];
                switch($template['type']){
                    case 'pdf':
                        $attachment['mime'] = 'application/pdf';
                        $attachment['name'] =  $template['name'].'.pdf';
                        break;
                    case 'word':
                        $attachment['mime'] = 'application/vnd.ms-word';
                        $attachment['name'] =  $template['name'].'.docx';
                        break;
                }
                $attachment['resources'] = ['request'=>$request['@id']];
//                $result = $this->commonGroundService->createResource($attachment, ['component'=>'bs', 'type'=>'attachments']);
                $attachments[] = $attachment;
            }
        }
        if(key_exists('order', $request) && $request['order'] != null){
            $application = $this->commonGroundService->getResource(['component' => 'wrc', 'type' => 'applications', 'id' => getenv('APP_ID')]);
            $order = $this->commonGroundService->getResource($request['order']);
            $orderTemplate = $this->commonGroundService->getResource($application['defaultConfiguration']['configuration']['orderTemplate']);
            $attachment = [];
            $attachment['uri'] = $orderTemplate['@id'];
            $attachment['mime'] = 'application/pdf';
            $attachment['name'] = "{$order['reference']}-Orderbevestiging.pdf";
            $attachment['resources'] = ['resource'=>$order['@id']];
            $attachments[] = $attachment;
            if(key_exists('invoice', $order) && $order['invoice'] != null){
                $invoice = $this->commonGroundService->getResource($order['invoice']);
                $invoiceTemplate = $this->commonGroundService->getResource($application['defaultConfiguration']['configuration']['invoiceTemplate']);
                $attachment = [];
                $attachment['uri'] = $invoiceTemplate['@id'];
                $attachment['mime'] = 'application/pdf';
                $attachment['name'] = "{$invoice['name']}-Factuur.pdf";
                $attachment['resources'] = ['resource'=>$invoice['@id']];
                $attachments[] = $attachment;
            }
        }

        $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-bevestiging"])['@id'];
        if(key_exists('contactpersoon', $request['properties'])) {
            $receiver = $request['properties']['contactpersoon'];
        } elseif(key_exists('factuur_persoon', $request['properties'])) {
            $receiver = $request['properties']['factuur_persoon'];
        } elseif(key_exists('aanvrager/rechthebbende',$request['properties']) && key_exists('contact', $assent = $this->commonGroundService->getResource($request['properties']['aanvrager/rechthebbende']))) {
            $receiver = $assent['contact'];
        } else {
            return 'Geen ontvanger gevonden';
        }
        $message = $this->createMessage($request, $content, $receiver, $attachments);

        return $this->commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages'])['@id'];
    }

    public function statusChange($webhook, $request){
        switch($request['status']){
            case "inProgress":
                $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-behandeling"])['@id'];
                break;
            case "rejected":
                $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-afwijzing"])['@id'];
                break;
            case "processed":
                $content = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id'=>"{$this->params->get('app_id')}/e-mail-afgehandeldg"])['@id'];
                break;

        }
        if(key_exists('contactpersoon', $request['properties'])) {
            $receiver = $request['properties']['contactpersoon'];
        } elseif(key_exists('factuur_persoon', $request['properties'])) {
            $receiver = $request['properties']['factuur_persoon'];
        } elseif(key_exists('aanvrager/rechthebbende',$request['properties']) && key_exists('contact', $assent = $this->commonGroundService->getResource($request['properties']['aanvrager/rechthebbende']))) {
            $receiver = $assent['contact'];
        } else {
            return 'Geen ontvanger gevonden';
        }
        $message = $this->createMessage($request, $content, $receiver);

        return $this->commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages'])['@id'];
    }

}
