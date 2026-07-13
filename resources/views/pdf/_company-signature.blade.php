@php($__c = $company ?? [])
<table style="width:100%; border-collapse:collapse; margin-top:22px;">
    <tr>
        <td style="width:60%;"></td>
        <td style="width:40%; text-align:center; font-size:10px; color:#333333;">
            <div style="margin-bottom:2px;">Hormat kami,</div>
            @if(!empty($__c['signature_url']))
                <img src="{{ $__c['signature_url'] }}" alt="ttd" style="max-height:70px; max-width:180px; margin:4px 0;">
            @else
                <div style="height:56px;"></div>
            @endif
            <div style="border-top:1px solid #333333; padding-top:3px;">{{ $__c['name'] ?? '' }}</div>
        </td>
    </tr>
</table>
