<?php // Shared site footer (links are placeholders for now). Only prints once per page.
if (defined("CAMIGO_FOOTER_DONE")) { return; }
define("CAMIGO_FOOTER_DONE", true);
?>
<footer class="site-footer">
    <div class="sf-left">
        <svg viewBox="0 0 48 48" aria-hidden="true">
            <defs>
                <linearGradient id="sfg" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#ffc857"/>
                    <stop offset="1" stop-color="#ff6b6b"/>
                </linearGradient>
            </defs>
            <path d="M34.72 33 A14 14 0 1 1 34.72 15" fill="none" stroke="url(#sfg)" stroke-width="6" stroke-linecap="round"/>
            <circle cx="39.5" cy="24" r="3.4" fill="#0c0f3a"/>
            <g transform="translate(24 25) scale(.78) translate(-24 -25)">
                <circle cx="17.6" cy="22.2" r="2.1" fill="#0c0f3a"/>
                <path d="M14.2 31 Q14.2 25.6 17.6 25.6 Q21 25.6 21 31 Z" fill="#0c0f3a"/>
                <circle cx="30.4" cy="22.2" r="2.1" fill="#0c0f3a"/>
                <path d="M27 31 Q27 25.6 30.4 25.6 Q33.8 25.6 33.8 31 Z" fill="#0c0f3a"/>
                <circle cx="24" cy="19.6" r="2.8" fill="url(#sfg)"/>
                <path d="M19.4 31.6 Q19.4 23.6 24 23.6 Q28.6 23.6 28.6 31.6 Z" fill="url(#sfg)"/>
            </g>
        </svg>
        <span>&copy; <?php echo date('Y'); ?> Camigo. All rights reserved.</span>
    </div>

    <nav class="sf-links" aria-label="Footer">
        <div class="sf-row">
            <a>FAQ</a>
            <a>About</a>
            <a>Terms</a>
            <a>Privacy</a>
            <a>Contact</a>
        </div>
        <a class="sf-dnsmpi">Do not share my personal information</a>
    </nav>

    <div class="sf-right">By GROUP 2</div>
</footer>