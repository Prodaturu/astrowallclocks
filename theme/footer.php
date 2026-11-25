</div></section></main>
<?php
$source = file_get_contents(get_theme_file_path('design/index.html'));
if (preg_match('~(<footer class="site-footer">.*?</footer>)~s', $source, $match)) {
    echo $match[1]; // Trusted design source.
}
?>
<script>const toggle=document.querySelector('.menu-toggle');const links=document.querySelector('.nav-links');if(toggle&&links){toggle.addEventListener('click',()=>{const open=toggle.getAttribute('aria-expanded')==='true';toggle.setAttribute('aria-expanded',String(!open));links.classList.toggle('is-open',!open)})}</script>
<?php wp_footer(); ?>
</body>
</html>
