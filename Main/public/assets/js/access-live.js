const video = document.getElementById("video");

const subtitleText = document.getElementById("subtitleText");

const transcriptArea = document.getElementById("transcript");

const btnClear = document.getElementById("btnClear");

const btnSave = document.getElementById("btnSave");

 

let lastWord = "";

let lastTime = 0;

 

function pushWord(word) {

  const now = Date.now();

  if (!word) return;

 

  if (word === lastWord && (now - lastTime) < 1500) return;

 

  lastWord = word;

  lastTime = now;

 

  // Sous-titre live

  subtitleText.textContent = word;

 

  // Ajouter dans la transcription

  transcriptArea.value += word + " ";

}

 

/* =============================

   MEDIA PIPE HANDS

============================= */

 

const hands = new Hands({

  locateFile: (file) => {

    return `https://cdn.jsdelivr.net/npm/@mediapipe/hands/${file}`;

  }

});

 

hands.setOptions({

  maxNumHands: 1,

  modelComplexity: 1,

  minDetectionConfidence: 0.7,

  minTrackingConfidence: 0.7

});

 

hands.onResults(results => {

  if (!results.multiHandLandmarks) return;

 

  const landmarks = results.multiHandLandmarks[0];

 

  const thumbTip = landmarks[4];

  const indexTip = landmarks[8];

  const middleTip = landmarks[12];

  const ringTip = landmarks[16];

  const pinkyTip = landmarks[20];

 

  const indexBase = landmarks[5];

 

  const indexUp = indexTip.y < indexBase.y;

  const middleUp = middleTip.y < indexBase.y;

  const ringUp = ringTip.y < indexBase.y;

  const pinkyUp = pinkyTip.y < indexBase.y;

 

  // ✋ Main ouverte

  if (indexUp && middleUp && ringUp && pinkyUp) {

    pushWord("Bonjour");

  }

 

  // ✊ Poing fermé

  else if (!indexUp && !middleUp && !ringUp && !pinkyUp) {

    pushWord("Merci");

  }

 

  // ☝ Index seul

  else if (indexUp && !middleUp && !ringUp && !pinkyUp) {

    pushWord("Oui");

  }

 

  // ✌ Index + majeur

  else if (indexUp && middleUp && !ringUp && !pinkyUp) {

    pushWord("Paix");

  }

 

  // 👍 Pouce levé

  else if (thumbTip.x < indexBase.x) {

    pushWord("Bravo");

  }

});

 

/* =============================

   CAMERA

============================= */

 

navigator.mediaDevices.getUserMedia({ video: true })

  .then(stream => {

    video.srcObject = stream;

  });

 

const camera = new Camera(video, {

  onFrame: async () => {

    await hands.send({ image: video });

  },

  width: 640,

  height: 480

});

 

camera.start();

 

/* =============================

   BUTTONS

============================= */

 

btnClear.addEventListener("click", () => {

  transcriptArea.value = "";

  subtitleText.textContent = "…";

});

 

btnSave.addEventListener("click", async () => {

  const text = transcriptArea.value.trim();

  if (!text) return alert("Rien à enregistrer");

 

  const res = await fetch(window.SAVE_URL, {

  method: "POST",

  headers: { "Content-Type": "application/x-www-form-urlencoded" },

  body: new URLSearchParams({ text: transcript.value })

});

 

  const data = await res.json();

 

  if (data.ok) {

    alert("Transcription enregistrée ✅");

  } else {

    alert("Erreur");

  }

});

 