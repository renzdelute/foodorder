const imagePreview = document.getElementById('imagePreview');
const fullscreen = document.getElementById('fullscreen');
const fullscreenImg = document.getElementById('fullscreenImg');
const closeBtn = document.getElementById('closeBtn');

imagePreview.addEventListener('click', () => {
    fullscreen.classList.add('active');

    fullscreenImg = imagePreview.src;
});


