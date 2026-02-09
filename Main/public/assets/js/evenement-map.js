document.addEventListener("DOMContentLoaded", async () => {
    const mapDiv = document.getElementById("map");
    if (!mapDiv) return;
  
    const nomLieu = mapDiv.dataset.nomlieu || "";
    const adresse = mapDiv.dataset.adresse || "";
    const ville = mapDiv.dataset.ville || "";
  
    // Requête complète pour la recherche
    const query = [nomLieu, adresse, ville, "Tunisie"].filter(Boolean).join(", ");
  
    // Init map (fallback: Tunis)
    const map = L.map("map").setView([36.8065, 10.1815], 12);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution: "&copy; OpenStreetMap contributors",
    }).addTo(map);
  
    // Si pas d’adresse => message
    if (!query.trim()) {
      L.popup()
        .setLatLng(map.getCenter())
        .setContent("Adresse non disponible.")
        .openOn(map);
      return;
    }
  
    // Géocodage via Nominatim (OpenStreetMap)
    try {
      const url =
        "https://nominatim.openstreetmap.org/search?format=json&limit=1&q=" +
        encodeURIComponent(query);
  
      const res = await fetch(url, {
        headers: {
          "Accept": "application/json",
        },
      });
  
      const data = await res.json();
  
      if (!data || data.length === 0) {
        L.popup()
          .setLatLng(map.getCenter())
          .setContent("Localisation introuvable. Vérifie l’adresse.")
          .openOn(map);
        return;
      }
  
      const lat = parseFloat(data[0].lat);
      const lng = parseFloat(data[0].lon);
  
      map.setView([lat, lng], 15);
  
      const label = `
        <b>${nomLieu || "Lieu de l’événement"}</b><br>
        ${adresse ? adresse + "<br>" : ""}
        ${ville ? ville : ""}
      `;
  
      L.marker([lat, lng]).addTo(map).bindPopup(label).openPopup();
    } catch (e) {
      console.error("Erreur géocodage:", e);
      L.popup()
        .setLatLng(map.getCenter())
        .setContent("Erreur lors du chargement de la carte.")
        .openOn(map);
    }
  });