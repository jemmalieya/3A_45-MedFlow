// Lightweight tilt + flip behaviour for SDG cards
document.addEventListener('DOMContentLoaded', function(){
  const cards = document.querySelectorAll('.sdg-card');
  cards.forEach(card => {
    const tilt = card.querySelector('.sdg-tilt');

    // Tilt on mouse move (write transform to inner tilt wrapper so hover flip still works)
    card.addEventListener('mousemove', function(e){
      if (!tilt) return;
      const rect = card.getBoundingClientRect();
      const x = (e.clientX - rect.left) / rect.width - 0.5;
      const y = (e.clientY - rect.top) / rect.height - 0.5;
      const rx = (y * 8); // rotateX
      const ry = (x * -12); // rotateY
      tilt.style.transform = `rotateX(${rx}deg) rotateY(${ry}deg) translateZ(0)`;
      tilt.style.transition = 'transform 0.12s ease';
    });

    card.addEventListener('mouseleave', function(){
      if (!tilt) return;
      tilt.style.transform = '';
      tilt.style.transition = 'transform 0.35s cubic-bezier(.2,.8,.2,1)';
    });
  });
});
