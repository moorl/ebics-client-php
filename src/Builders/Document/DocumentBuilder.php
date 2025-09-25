<?php declare(strict_types=1);

namespace EbicsApi\Ebics\Builders\Document;

use DateTime;
use DOMElement;
use EbicsApi\Ebics\Contracts\PostalAddressInterface;
use EbicsApi\Ebics\Handlers\Traits\XPathTrait;
use EbicsApi\Ebics\Models\XmlData;
use EbicsApi\Ebics\Services\DOMHelper;
use EbicsApi\Ebics\Services\RandomService;

/**
 * DocumentBuilder with most common methods
 * The validation against oder data need namespaced XML documents
 *
 * @license http://www.opensource.org/licenses/mit-license.html  MIT License
 * @author Jan-Philipp Georg (moori)
 */
abstract class DocumentBuilder
{
    use XPathTrait;

    protected RandomService $randomService;
    protected ?XmlData $instance = null;
    protected string $ns;

    public function __construct(
        protected string $schema = 'pain.001.001.09.xsd'
    ) {
        $this->randomService = new RandomService();

        $split = explode('_', $this->schema);
        $this->ns = sprintf("urn:iso:std:iso:20022:tech:xsd:%s", $split[0]);
    }

    public function popInstance(): XmlData
    {
        $instance = $this->instance;
        $this->instance = null;
        return $instance;
    }

    protected function createCreditTransferTransactionElement(
        float $amount,
        ?string $instrId = null,
        ?string $endToEndId = null
    ): DOMElement {
        $xp = $this->xp();

        $pmtInf = DOMHelper::safeItem($xp->query("//p:CstmrCdtTrfInitn/p:PmtInf"));

        $tx = $this->el('CdtTrfTxInf');
        $pmtInf->appendChild($tx);

        $pmtId = $this->el('PmtId');
        if ($instrId !== null) {
            $pmtId->appendChild($this->el('InstrId', $instrId));
        }
        $pmtId->appendChild($this->el('EndToEndId', $endToEndId ?: $this->randomService->uniqueIdWithDate('pete')));
        $tx->appendChild($pmtId);

        $nbGrpEl = DOMHelper::safeItem($xp->query("//p:CstmrCdtTrfInitn/p:GrpHdr/p:NbOfTxs"));
        $sumGrpEl = DOMHelper::safeItem($xp->query("//p:CstmrCdtTrfInitn/p:GrpHdr/p:CtrlSum"));
        $nbPmtEl = DOMHelper::safeItem($xp->query("//p:CstmrCdtTrfInitn/p:PmtInf/p:NbOfTxs"));
        $sumPmtEl = DOMHelper::safeItem($xp->query("//p:CstmrCdtTrfInitn/p:PmtInf/p:CtrlSum"));

        $nb = (int)trim($nbGrpEl->textContent);
        $nb++;
        $nbGrpEl->textContent = (string)$nb;
        $nbPmtEl->textContent = (string)$nb;

        $currentSum = (float)str_replace(',', '.', trim($sumGrpEl->textContent));
        $newSum = $currentSum + $amount;
        $sumGrpEl->textContent = number_format($newSum, 2, '.', '');
        $sumPmtEl->textContent = number_format($newSum, 2, '.', '');

        return $tx;
    }

    protected function addAmountElement(DOMElement $tx, float $amount, string $currency): void
    {
        $amt = $this->el('Amt');
        $instd = $this->el('InstdAmt', number_format($amount, 2, '.', ''));
        $instd->setAttribute('Ccy', $currency);
        $amt->appendChild($instd);
        $tx->appendChild($amt);
    }

    protected function addCreditor(
        DOMElement $tx,
        ?string $creditorFinInstBIC,
        string $creditorIBAN,
        string $creditorName,
        ?PostalAddressInterface $postalAddress,
        ?string $purposeText = null,
        ?string $purposeCode = null
    ): void {
        if ($creditorFinInstBIC !== null) {
            $cdtrAgt = $this->el('CdtrAgt');
            $fi = $this->el('FinInstnId');
            $fi->appendChild($this->el('BICFI', $creditorFinInstBIC));
            $cdtrAgt->appendChild($fi);
            $tx->appendChild($cdtrAgt);
        }

        $cdtr = $this->el('Cdtr');
        $cdtr->appendChild($this->el('Nm', $creditorName));
        if ($postalAddress !== null) {
            $cdtr->appendChild($postalAddress->toDomElement($this->instance));
        }
        $tx->appendChild($cdtr);

        $cdtrAcct = $this->el('CdtrAcct');
        $id = $this->el('Id');
        $id->appendChild($this->el('IBAN', str_replace(' ', '', $creditorIBAN)));
        $cdtrAcct->appendChild($id);
        $tx->appendChild($cdtrAcct);

        if ($purposeCode) {
            $purp = $this->el('Purp');
            $purp->appendChild($this->el('Cd', $purposeCode));
            $tx->appendChild($purp);
        }

        if ($purposeText !== null) {
            $rmt = $this->el('RmtInf');
            $rmt->appendChild($this->el('Ustrd', $purposeText));
            $tx->appendChild($rmt);
        }
    }

    protected function el(string $name, ?string $text = null): DOMElement
    {
        $el = $this->instance->createElementNS($this->ns, $name);
        if ($text !== null) {
            $el->nodeValue = $text;
        }
        return $el;
    }

    protected function xp(): \DOMXPath
    {
        $xp = $this->prepareXPath($this->instance);
        $xp->registerNamespace('p', $this->ns);
        return $xp;
    }

    protected function buildReqdExctnDt(DateTime $date): DOMElement
    {
        $req = $this->el('ReqdExctnDt');
        $req->appendChild($this->el('Dt', $date->format('Y-m-d')));
        return $req;
    }

    protected function schemaLocation(): string
    {
        return sprintf("%s %s.xsd", $this->ns, $this->schema);
    }
}
