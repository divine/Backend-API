<?php

namespace App\Models;

use App\Helpers\IpUtils;
use Elasticquent\ElasticquentTrait;
use Illuminate\Database\Eloquent\Model;

class ASN extends Model {

    use ElasticquentTrait;

    /**
     * The elasticsearch settings.
     *
     * @var array
     */
    protected $indexSettings = [
        'analysis' => [
            'analyzer' => [
                'string_lowercase' => [
                    'tokenizer' => 'keyword',
                    'filter' => [ 'asciifolding', 'lowercase', 'custom_replace' ],
                ],
            ],
            'filter' => [
                'custom_replace' => [
                    'type' => 'pattern_replace',
                    'pattern' => "[^a-z0-9 ]",
                    'replacement' => "",
                ],
            ],
        ],
    ];

    /**
     * The elasticsearch mappings.
     *
     * @var array
     */
    protected $mappingProperties = [
        'name' => [
            'type' => 'string',
            'analyzer' => 'string_lowercase'
        ],
        'description' => [
            'type' => 'string',
            'analyzer' => 'string_lowercase'
        ],
        'asn' => [
            'type' => 'string',
	        'fields' => [
                'sort' => ['type' => 'long'],
            ],
        ],
    ];

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'asns';

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['id', 'rir_id', 'raw_whois', 'created_at', 'updated_at'];


    public function emails()
    {
        return $this->hasMany('App\Models\ASNEmail', 'asn_id', 'id');
    }

    public function rir()
    {
        return $this->belongsTo('App\Models\Rir');
    }

    public function ipv4_prefixes()
    {
        return $this->hasMany('App\Models\IPv4BgpPrefix', 'asn', 'asn');
    }

    public function ipv6_prefixes()
    {
        return $this->hasMany('App\Models\IPv6BgpPrefix', 'asn', 'asn');
    }

    public function getDescriptionFullAttribute($value)
    {
        return json_decode($value);
    }

    public function getOwnerAddressAttribute($value)
    {
        if (is_null($value) === true) {
            return null;
        }

        $data = json_decode($value);
        $addressLines = [];

        if (is_object($data) !== true && is_array($data) !== true) {
            return $addressLines;
        }

        foreach($data as $entry) {
            // Remove/Clean all double commas
            $entry = preg_replace('/,+/', ',', $entry);
            $addressArr = explode(',', $entry);
            $addressLines = array_merge($addressLines, $addressArr);
        }

        return array_map('trim', $addressLines);
    }

    public function getRawWhoisAttribute($value)
    {
        // Remove the "source" entry
        $parts = explode("\n", $value);
        unset($parts[0]);
        return implode($parts, "\n");
    }

    public function getEmailContactsAttribute()
    {
        $email_contacts = [];
        foreach ($this->emails as $email) {
                 $email_contacts[] = $email->email_address;
        }
        return $email_contacts;
    }

    public function getAbuseContactsAttribute()
    {
        $abuse_contacts = [];
        foreach ($this->emails as $email) {
            if ($email->abuse_email) {
                $abuse_contacts[] = $email->email_address;
            }
        }
        return $abuse_contacts;
    }

    public static function getPeers($as_number)
    {
        $peerSet['ipv4_peers'] = IPv4Peer::where('asn_1', $as_number)->orWhere('asn_2', $as_number)->get();
        $peerSet['ipv6_peers'] = IPv6Peer::where('asn_1', $as_number)->orWhere('asn_2', $as_number)->get();
        $output['ipv4_peers'] = [];
        $output['ipv6_peers'] = [];

        foreach ($peerSet as $ipVersion => $peers) {
            foreach ($peers as $peer) {
                if ($peer->asn_1 == $as_number && $peer->asn_2 == $as_number) {
                    continue;
                }

                $peerAsn = $peer->asn_1 == $as_number ? $peer->asn_2 : $peer->asn_1;
                $asn = self::where('asn', $peerAsn)->first();

                $peerAsnInfo['asn']             = $peerAsn;
                $peerAsnInfo['name']            = is_null($asn) ? null : $asn->name;
                $peerAsnInfo['description']     = is_null($asn) ? null : $asn->description;
                $peerAsnInfo['country_code']    = is_null($asn) ? null : $asn->counrty_code;

                $output[$ipVersion][] = $peerAsnInfo;
            }
        }

        return $output;
    }

    public static function getPrefixes($as_number)
    {
        $prefixes = (new IpUtils())->getBgpPrefixes($as_number);

        $rirNames = [];
        foreach (Rir::all() as $rir) {
            $rirNames[$rir->id] = $rir->name;
        }

        $output['ipv4_prefixes'] = [];
        foreach ($prefixes['ipv4'] as $prefix) {
            $prefixWhois = $prefix->whois;

            $prefixOutput['prefix']         = $prefix->ip . '/' . $prefix->cidr;
            $prefixOutput['ip']             = $prefix->ip;
            $prefixOutput['cidr']           = $prefix->cidr;
            $prefixOutput['roa_status']     = $prefix->roa_status;

            $prefixOutput['name']           = isset($prefixWhois->name) ? $prefixWhois->name : null;
            $prefixOutput['description']    = isset($prefixWhois->description) ? $prefixWhois->description : null;
            $prefixOutput['country_code']   = isset($prefixWhois->counrty_code) ? $prefixWhois->counrty_code : null;

            $prefixOutput['parent']['prefix']   = empty($prefixWhois->parent_ip) !== true && isset($prefixWhois->parent_cidr) ? $prefixWhois->parent_ip . '/' . $prefixWhois->parent_cidr : null;
            $prefixOutput['parent']['ip']       = empty($prefixWhois->parent_ip) !== true ? $prefixWhois->parent_ip : null;
            $prefixOutput['parent']['cidr']     = empty($prefixWhois->parent_cidr) !== true ? $prefixWhois->parent_cidr : null;
            $prefixOutput['parent']['rir_name'] = empty($prefixWhois->rir_id) !== true ? $rirNames[$prefixWhois->rir_id] : null;
            $prefixOutput['parent']['allocation_status']    = empty($prefixWhois->status) !== true ? $prefixWhois->status : 'unknown';

            $output['ipv4_prefixes'][]  = $prefixOutput;
            $prefixOutput = null;
            $prefixWhois = null;
        }

        $output['ipv6_prefixes'] = [];
        foreach ($prefixes['ipv6'] as $prefix) {
            $prefixWhois = $prefix->whois;

            $prefixOutput['prefix'] = $prefix->ip . '/' . $prefix->cidr;
            $prefixOutput['ip']     = $prefix->ip;
            $prefixOutput['cidr']   = $prefix->cidr;
            $prefixOutput['roa_status']     = $prefix->roa_status;

            $prefixOutput['name']           = isset($prefixWhois->name) ? $prefixWhois->name : null;
            $prefixOutput['description']    = isset($prefixWhois->description) ? $prefixWhois->description : null;
            $prefixOutput['country_code']   = isset($prefixWhois->counrty_code) ? $prefixWhois->counrty_code : null;

            $prefixOutput['parent']['prefix']   = empty($prefixWhois->parent_ip) !== true && isset($prefixWhois->parent_cidr) ? $prefixWhois->parent_ip . '/' . $prefixWhois->parent_cidr : null;
            $prefixOutput['parent']['ip']       = empty($prefixWhois->parent_ip) !== true ? $prefixWhois->parent_ip : null;
            $prefixOutput['parent']['cidr']     = empty($prefixWhois->parent_cidr) !== true ? $prefixWhois->parent_cidr : null;
            $prefixOutput['parent']['rir_name'] = empty($prefixWhois->rir_id) !== true ? $rirNames[$prefixWhois->rir_id] : null;
            $prefixOutput['parent']['allocation_status']    = empty($prefixWhois->status) !== true ? $prefixWhois->status : 'unknown';

            $output['ipv6_prefixes'][]  = $prefixOutput;
            $prefixOutput = null;
            $prefixWhois = null;
        }

        return $output;
    }

    public static function getUpstreams($as_number)
    {
        $ipv4Upstreams = IPv4BgpEntry::where('asn', $as_number)->orderBy('asn', 'asc')->get();
        $ipv6Upstreams = IPv6BgpEntry::where('asn', $as_number)->orderBy('asn', 'asc')->get();

        $output['ipv4_upstreams'] = [];
        foreach ($ipv4Upstreams as $upstream) {

            if (isset($output['ipv4_upstreams'][$upstream->upstream_asn]) === true) {
                if (in_array($upstream->bgp_path, $output['ipv4_upstreams'][$upstream->upstream_asn]['bgp_paths']) === false) {
                    $output['ipv4_upstreams'][$upstream->upstream_asn]['bgp_paths'][] = $upstream->bgp_path;
                }
                continue;
            }

            $upstreamAsn = self::where('asn', $upstream->upstream_asn)->first();

            $upstreamOutput['asn']          = $upstream->upstream_asn;
            $upstreamOutput['name']         = isset($upstreamAsn->name) ? $upstreamAsn->name : null;
            $upstreamOutput['description']  = isset($upstreamAsn->description) ? $upstreamAsn->description : null;
            $upstreamOutput['country_code'] = isset($upstreamAsn->counrty_code) ? $upstreamAsn->counrty_code : null;
            $upstreamOutput['bgp_paths'][]  = $upstream->bgp_path;

            $output['ipv4_upstreams'][$upstream->upstream_asn]  = $upstreamOutput;
            $upstreamOutput = null;
            $upstreamAsn = null;
        }

        $output['ipv6_upstreams'] = [];
        foreach ($ipv6Upstreams as $upstream) {

            if (isset($output['ipv6_upstreams'][$upstream->upstream_asn]) === true) {
                if (in_array($upstream->bgp_path, $output['ipv6_upstreams'][$upstream->upstream_asn]['bgp_paths']) === false) {
                    $output['ipv6_upstreams'][$upstream->upstream_asn]['bgp_paths'][] = $upstream->bgp_path;
                }
                continue;
            }

            $upstreamAsn = self::where('asn', $upstream->upstream_asn)->first();

            $upstreamOutput['asn']          = $upstream->upstream_asn;
            $upstreamOutput['name']         = isset($upstreamAsn->name) ? $upstreamAsn->name : null;
            $upstreamOutput['description']  = isset($upstreamAsn->description) ? $upstreamAsn->description : null;
            $upstreamOutput['country_code'] = isset($upstreamAsn->counrty_code) ? $upstreamAsn->counrty_code : null;
            $upstreamOutput['bgp_paths'][]  = $upstream->bgp_path;

            $output['ipv6_upstreams'][$upstream->upstream_asn]  = $upstreamOutput;
            $upstreamOutput = null;
            $upstreamAsn = null;
        }

        $output['ipv4_upstreams'] = array_values($output['ipv4_upstreams']);
        $output['ipv6_upstreams'] = array_values($output['ipv6_upstreams']);

        return $output;
    }

    public static function getDownstreams($as_number)
    {
        $ipv4Downstreams = IPv4BgpEntry::where('upstream_asn', $as_number)->orderBy('upstream_asn', 'asc')->get();
        $ipv6Downstreams = IPv6BgpEntry::where('upstream_asn', $as_number)->orderBy('upstream_asn', 'asc')->get();

        $output['ipv4_downstreams'] = [];
        foreach ($ipv4Downstreams as $downstream) {

            if (isset($output['ipv4_downstreams'][$downstream->asn]) === true) {
                if (in_array($downstream->bgp_path, $output['ipv4_downstreams'][$downstream->asn]['bgp_paths']) === false) {
                    $output['ipv4_downstreams'][$downstream->asn]['bgp_paths'][] = $downstream->bgp_path;
                }
                continue;
            }

            $downstreamAsn = self::where('asn', $downstream->asn)->first();

            $downstreamOutput['asn']          = $downstreamAsn->asn;
            $downstreamOutput['name']         = isset($downstreamAsn->name) ? $downstreamAsn->name : null;
            $downstreamOutput['description']  = isset($downstreamAsn->description) ? $downstreamAsn->description : null;
            $downstreamOutput['country_code'] = isset($downstreamAsn->counrty_code) ? $downstreamAsn->counrty_code : null;
            $downstreamOutput['bgp_paths'][]  = $downstream->bgp_path;

            $output['ipv4_downstreams'][$downstream->asn]  = $downstreamOutput;
            $downstreamOutput = null;
            $downstreamAsn = null;
        }

        $output['ipv6_downstreams'] = [];
        foreach ($ipv6Downstreams as $downstream) {

            if (isset($output['ipv6_downstreams'][$downstream->asn]) === true) {
                if (in_array($downstream->bgp_path, $output['ipv6_downstreams'][$downstream->asn]['bgp_paths']) === false) {
                    $output['ipv6_downstreams'][$downstream->asn]['bgp_paths'][] = $downstream->bgp_path;
                }
                continue;
            }

            $downstreamAsn = self::where('asn', $downstream->asn)->first();

            $downstreamOutput['asn']          = $downstream->asn;
            $downstreamOutput['name']         = isset($downstreamAsn->name) ? $downstreamAsn->name : null;
            $downstreamOutput['description']  = isset($downstreamAsn->description) ? $downstreamAsn->description : null;
            $downstreamOutput['country_code'] = isset($downstreamAsn->counrty_code) ? $downstreamAsn->counrty_code : null;
            $downstreamOutput['bgp_paths'][]  = $downstream->bgp_path;

            $output['ipv6_downstreams'][$downstream->asn]  = $downstreamOutput;
            $downstreamOutput = null;
            $downstreamAsn = null;
        }

        $output['ipv4_downstreams'] = array_values($output['ipv4_downstreams']);
        $output['ipv6_downstreams'] = array_values($output['ipv6_downstreams']);

        return $output;
    }
}
