<?php
declare(strict_types=1);

namespace Solportalen\Integration\Supplier;

use PDO;
use RuntimeException;
use Solportalen\Config\Env;

final class ElprisSupplierService
{
    public function __construct(private readonly PDO $pdo) {}

    public function refresh(bool $force=false): string
    {
        $last=$this->pdo->query("SELECT MAX(fetched_at) FROM supplier_watch_runs WHERE status='ok'")->fetchColumn();
        if(!$force&&is_string($last)&&time()-strtotime($last.' UTC')<7*86400)return 'cache er stadig frisk';
        $area=preg_replace('/[^0-9]/','',(string)Env::get('ELPRIS_GRID_AREA','791'));
        if($area==='')throw new RuntimeException('ELPRIS_GRID_AREA er ugyldigt.');
        $url=rtrim((string)Env::get('ELPRIS_BASE_URL','https://elpris.dk'),'/').'/data/products_'.$area.'.json';
        $body=$this->get($url);$payload=json_decode($body,true,64,JSON_THROW_ON_ERROR);$products=$payload['products']??null;
        if(!is_array($products))throw new RuntimeException('Elpris-produktfilen har ukendt format.');
        $annual=max(1,(int)Env::get('ANNUAL_ELECTRICITY_CONSUMPTION_KWH','12000'));
        $spot=max(0.0,(float)Env::get('SUPPLIER_COMPARISON_SPOT_DKK_KWH_EX_VAT','.70'));
        $baselineRate=max(0.0,(float)Env::get('CURRENT_SUPPLIER_ENERGY_DKK_KWH_EX_VAT','.70'));
        $baselineSubscription=max(0.0,(float)Env::get('CURRENT_SUPPLIER_SUBSCRIPTION_DKK_MONTH_EX_VAT','0'));
        $baseline=($annual*$baselineRate+12*$baselineSubscription)*1.25;
        $rows=[];
        foreach($products as$product){$row=$this->normalize($product,$annual,$spot,$baseline,$url);if($row!==null)$rows[]=$row;}
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('DELETE FROM supplier_offers WHERE source_key=?')->execute(['elpris_'.$area]);
            $insert=$this->pdo->prepare('INSERT INTO supplier_offers(source_key,product_id,supplier_name,product_name,billing_type,binding_period,energy_price_dkk_kwh_ex_vat,subscription_dkk_month_ex_vat,annual_supplier_cost_dkk_inc_vat,annual_saving_dkk_inc_vat,maximum_consumption_kwh,valid_from,valid_to,source_updated_at,source_url,caveats_json,fetched_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
            foreach($rows as$row)$insert->execute(array_merge(['elpris_'.$area],$row));
            $run=$this->pdo->prepare('INSERT INTO supplier_watch_runs(source_key,fetched_at,status,offer_count,source_hash,details_json) VALUES(?,UTC_TIMESTAMP(6),"ok",?,?,?)');
            $run->execute(['elpris_'.$area,count($rows),hash('sha256',$body),json_encode(['area'=>$area,'annual_kwh'=>$annual,'spot_assumption_dkk_kwh_ex_vat'=>$spot,'baseline_supplier_rate_dkk_kwh_ex_vat'=>$baselineRate,'source_url'=>$url],JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();
        }catch(\Throwable $e){$this->pdo->rollBack();throw$e;}
        return count($rows).' anvendelige leverandørprodukter';
    }

    public function comparison(): array
    {
        $offers=$this->pdo->query('SELECT supplier_name,product_name,billing_type,binding_period,energy_price_dkk_kwh_ex_vat,subscription_dkk_month_ex_vat,annual_supplier_cost_dkk_inc_vat,annual_saving_dkk_inc_vat,maximum_consumption_kwh,valid_from,valid_to,source_updated_at,source_url,caveats_json,fetched_at FROM supplier_offers ORDER BY annual_supplier_cost_dkk_inc_vat ASC LIMIT 40')->fetchAll();
        foreach($offers as&$offer)$offer['caveats']=json_decode((string)$offer['caveats_json'],true)?:[];unset($offer['caveats_json']);unset($offer);
        $run=$this->pdo->query('SELECT fetched_at,status,offer_count,details_json FROM supplier_watch_runs ORDER BY id DESC LIMIT 1')->fetch();
        return ['current'=>['supplier'=>Env::get('CURRENT_SUPPLIER_NAME','Vindstød'),'annual_kwh'=>(int)Env::get('ANNUAL_ELECTRICITY_CONSUMPTION_KWH','12000'),'energy_rate_dkk_kwh_ex_vat'=>(float)Env::get('CURRENT_SUPPLIER_ENERGY_DKK_KWH_EX_VAT','.70'),'subscription_dkk_month_ex_vat'=>(float)Env::get('CURRENT_SUPPLIER_SUBSCRIPTION_DKK_MONTH_EX_VAT','0')],'offers'=>$offers,'last_run'=>$run?:null,'export_comparison_included'=>false];
    }

    private function normalize(array $product,int $annual,float $spot,float $baseline,string $url):?array
    {
        if(($product['private1']??false)!==true)return null;
        $price=null;foreach(($product['productPrices']??[])as$candidate){$min=(int)($candidate['minimumConsumption']??0);$max=isset($candidate['maximumConsumption'])?(int)$candidate['maximumConsumption']:PHP_INT_MAX;if($annual>=$min&&$annual<=$max){$price=$candidate;break;}}
        if(!is_array($price)||!isset($price['matrix'])||!is_array($price['matrix']))return null;
        $weighted=0.0;$covered=0.0;foreach($price['matrix']as$line){$from=max(0,(int)($line['hoursFrom']??0));$to=min(24,(int)($line['hoursTo']??24));$hours=max(0,$to-$from);$weighted+=(float)($line['amount']??0)*$hours;$covered+=$hours;}
        if($covered<=0)return null;$supplierOre=$weighted/$covered;$energy=$supplierOre/100+(($price['additionalNordPoolSpot']??false)?$spot:0.0);
        $subscription=max(0.0,(float)($price['subscription']??0));$fees=0.0;foreach(($product['fees']??[])as$fee){if(in_array($fee['tooltipKey']??'',['feeSupplierChange','feeProductChange'],true))continue;if(($fee['feeIsDefaultFormat']??null)===true)$fees+=max(0.0,(float)($fee['amount']??0))*$annual/100;elseif(($fee['feeIsDefaultFormat']??null)===false)$fees+=max(0.0,(float)($fee['amount']??0))*12;}
        $discount=max(0.0,(float)($product['firstTimeDiscount']??0));$cost=max(0.0,($annual*$energy+12*$subscription+$fees-$discount)*1.25);
        $caveats=['Spotantagelse anvendes ved variable produkter.','Producent-/soloverskudsaftale er ikke indeholdt i elpris.dk-produktfilen.'];if(($product['introOffer']??false)===true)$caveats[]='Produktet indeholder et introduktionstilbud.';if(($product['bindingPeriod']??'0 mdr.')!=='0 mdr.')$caveats[]='Produktet har binding eller skiftevilkår.';if(!empty($price['otherDays']))$caveats[]='Produktet har særlige dagspriser; estimatet er forenklet.';
        return [(string)($product['id']??$product['productId']??''),(string)($product['supplier']['name']??'Ukendt'),(string)($product['name']??'Ukendt'),(string)($product['billingType']??'unknown'),(string)($product['bindingPeriod']??''),round($energy,6),round($subscription,2),round($cost,2),round($baseline-$cost,2),isset($price['maximumConsumption'])?(int)$price['maximumConsumption']:null,$price['validFrom']??null,$price['validTo']??null,$price['lastUpdate']??null,$url,json_encode($caveats,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)];
    }

    private function get(string $url):string
    {
        $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>35,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_USERAGENT=>'Solportalen supplier-watch/0.1',CURLOPT_HTTPHEADER=>['Accept: application/json']]);$body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);if(!is_string($body)||$status<200||$status>=300)throw new RuntimeException('Elpris.dk HTTP '.$status.($error!==''?': '.$error:''));return$body;
    }
}
