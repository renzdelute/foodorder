const imagePreview = document.getElementById('imagePreview');
const fullscreen = document.getElementById('fullscreen');
const fullscreenImg = document.getElementById('fullscreenImg');
const closeBtn = document.getElementById('closeBtn');

document.querySelectorAll('.imgUrl').forEach(img => {
    img.addEventListener('click', () => {
        fullscreen.classList.add('active');

        fullscreenImg = img.src;
    });
});

closeBtn.addEventListener('click', () => {
    fullscreen.classList.remove('active');
});

fullscreen.addEventListener('click', () => {
    fullscreen.classList.remove('active');
});
