{{--
    The RANS logo of the Holiday Greetings emails: no container, dark blue on white, the yellow tagline bar and "Your Contractor" in orange. The R and the NS are two small
    cut-outs of the real logo; the temple between them is built in HTML and CSS so it can rebuild itself in the mail apps that run CSS animation.
    Needs: $logoR, $logoNS, $navy, $gold, $f, $cols
--}}
{{-- the logo: no container, dark blue on the white of the email, the yellow tagline bar of the brand and "Your Contractor" in orange --}}
        <tr>
            <td align="center" bgcolor="#ffffff" style="background-color:#ffffff; padding:34px 20px 26px;">
                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                    <tr><td>
                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                            <tr>
                                <td valign="bottom" style="padding:0;">@if($logoR)<img src="{{ $logoR }}" alt="R" width="70" height="75" style="display:block; width:70px; height:75px; border:0;" />@else<span style="font-size:64px; font-weight:800; color:{{ $navy }};">R</span>@endif</td>
                                <td valign="bottom" style="padding:0 5px;">
                                    {{-- the temple, built in code: roof, beam, seven columns, two steps --}}
                                    <table role="presentation" cellpadding="0" cellspacing="0" width="104" style="width:104px;">
                                        <tr><td align="center" style="padding:0; line-height:0; font-size:0;"><div class="a-roof" style="width:0; height:0; margin:0 auto; border-left:52px solid transparent; border-right:52px solid transparent; border-bottom:27px solid {{ $navy }};"></div></td></tr>
                                        <tr><td align="center" style="padding:2px 0 3px; line-height:0; font-size:0;"><div style="width:98px; height:4px; margin:0 auto; background-color:{{ $navy }}; border-radius:2px;"></div></td></tr>
                                        <tr><td align="center" style="padding:0; line-height:0; font-size:0;">
                                            <table role="presentation" cellpadding="0" cellspacing="0" align="center"><tr>
                                                @for($c = 0; $c < $cols; $c++)
                                                <td valign="bottom" style="padding:0 2.6px; line-height:0; font-size:0;"><div class="a-col" style="width:9px; height:29px; background-color:{{ $navy }}; border-radius:2px 2px 0 0; animation-delay:-{{ number_format(9 - $c * 0.24, 2) }}s;"></div></td>
                                                @endfor
                                            </tr></table>
                                        </td></tr>
                                        <tr><td align="center" style="padding:2px 0 0; line-height:0; font-size:0;"><div style="width:102px; height:3px; margin:0 auto; background-color:{{ $navy }}; border-radius:2px;"></div></td></tr>
                                        <tr><td align="center" style="padding:2px 0 0; line-height:0; font-size:0;"><div style="width:96px; height:3px; margin:0 auto; background-color:{{ $navy }}; border-radius:2px;"></div></td></tr>
                                    </table>
                                </td>
                                <td valign="bottom" style="padding:0;">@if($logoNS)<img src="{{ $logoNS }}" alt="NS" width="122" height="75" style="display:block; width:122px; height:75px; border:0;" />@else<span style="font-size:64px; font-weight:800; color:{{ $navy }};">NS</span>@endif</td>
                            </tr>
                        </table>
                        <div style="margin:9px 0 0; padding:6px 0; background-color:{{ $gold }}; border-radius:3px; text-align:center; font-family:{!! $f !!}; font-size:9.5px; font-weight:700; letter-spacing:.17em; color:{{ $navy }}; text-transform:uppercase;">Building &amp; Civil Contractor</div>
                        <div style="margin:7px 0 0; text-align:center; font-family:{!! $f !!}; font-size:11px; font-weight:700; letter-spacing:.14em; color:#fb923c; text-transform:uppercase;">Your Contractor</div>
                    </td></tr>
                </table>
            </td>
        </tr>

