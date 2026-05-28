# Optional: forwarding the collage off your local network

Default install hosts the collage at `http://birdnet.local/collage/` on
your LAN with no auth. If you want it accessible from anywhere — or
piped into Home Assistant / MQTT — pick one of the recipes below.

Each recipe is independent. Skip what you don't need.

---

## 1. Cloudflare Tunnel (recommended for public access)

Gives you a public HTTPS URL with no port forwarding and no exposed
home IP. Free Cloudflare account required.

Install `cloudflared` on the Pi:

```bash
sudo mkdir -p /usr/share/keyrings
curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg \
  | sudo tee /usr/share/keyrings/cloudflare-main.gpg >/dev/null
echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared $(lsb_release -cs) main' \
  | sudo tee /etc/apt/sources.list.d/cloudflared.list
sudo apt update && sudo apt install -y cloudflared
```

Authenticate + create the tunnel:

```bash
cloudflared tunnel login
cloudflared tunnel create birds
```

Add a public route — pick a hostname on a zone you own:

```bash
cloudflared tunnel route dns birds birds.your-domain.com
```

Configure the tunnel to point at the local Caddy:

```bash
sudo cp avian/forwarding/cloudflared.yml /etc/cloudflared/config.yml
# edit /etc/cloudflared/config.yml — set `tunnel:` to your tunnel UUID
sudo cloudflared service install
sudo systemctl restart cloudflared
```

**Adding password protection on the public URL.** With Cloudflare in
front, gate the public endpoint via Cloudflare Access (zero-trust
free tier supports up to 50 users) — see Cloudflare docs. The local
LAN URL remains unprotected. If you'd rather use HTTP Basic auth on
Caddy itself, see [forwarding/caddy-auth.caddy](caddy-auth.caddy).

---

## 2. Home Assistant — surface latest detection as a sensor

Add to `configuration.yaml`:

```yaml
rest:
  - resource: http://birdnet.local/api/recent.json?hours=1
    scan_interval: 60
    sensor:
      - name: "Latest Bird"
        value_template: >
          {% set top = value_json.species | sort(attribute='last_seen', reverse=true) | first %}
          {{ top.com if top else 'none' }}
        json_attributes_path: "$.species[0]"
        json_attributes:
          - sci
          - n
          - last_seen
          - best_conf
```

Use the sensor in automations — flash a light when a new species is
heard, etc.

---

## 3. MQTT — fan out detections to other services

If you already run an MQTT broker, publish every new detection.
Install `paho-mqtt` and run the bridge:

```bash
sudo pip3 install paho-mqtt --break-system-packages
cp avian/forwarding/mqtt-bridge.py ~/avian-mqtt.py
# edit ~/avian-mqtt.py — broker host, topic prefix
sudo cp avian/forwarding/avian-mqtt.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now avian-mqtt
```

The bridge polls `/api/recent.json?hours=1` once a minute and
publishes new species under `birdnet/<slug>` with the full record as
JSON payload.
