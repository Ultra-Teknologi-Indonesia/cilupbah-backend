@php($__c = $company ?? [])
<table style="width:100%; border-collapse:collapse; margin-bottom:10px; border-bottom:1.5px solid #333333;">
    <tr>
        @if(!empty($__c['logo_url']))
            <td style="width:64px; padding:0 10px 8px 0; vertical-align:middle;">
                <img src="{{ $__c['logo_url'] }}" alt="logo" style="max-height:52px; max-width:64px;">
            </td>
        @endif
        <td style="vertical-align:middle; padding-bottom:8px;">
            <div style="font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.3px; color:#1a1a1a;">{{ $__c['name'] ?? '' }}</div>
            @if(!empty($__c['brand']))
                <div style="font-size:10px; color:#555555;">{{ $__c['brand'] }}</div>
            @endif
            @if(!empty($__c['address']))
                <div style="font-size:9px; color:#555555; margin-top:2px;">{{ $__c['address'] }}@if(!empty($__c['city'])), {{ $__c['city'] }}@endif @if(!empty($__c['postal_code'])) {{ $__c['postal_code'] }}@endif</div>
            @endif
            @if(!empty($__c['phone']) || !empty($__c['npwp']) || !empty($__c['email']))
                <div style="font-size:9px; color:#555555;">
                    @if(!empty($__c['phone']))Telp: {{ $__c['phone'] }}@endif
                    @if(!empty($__c['email']))@if(!empty($__c['phone'])) &middot; @endif{{ $__c['email'] }}@endif
                    @if(!empty($__c['npwp']))@if(!empty($__c['phone']) || !empty($__c['email'])) &middot; @endif NPWP: {{ $__c['npwp'] }}@endif
                </div>
            @endif
        </td>
    </tr>
</table>
