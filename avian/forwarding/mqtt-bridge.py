#!/usr/bin/env python3
"""Poll /api/recent.json on the Pi once a minute and publish each new
detection to MQTT. Edit BROKER, TOPIC_PREFIX, and PI_URL below."""
import json
import time
import urllib.request
import paho.mqtt.client as mqtt  # sudo pip3 install paho-mqtt

BROKER = "homeassistant.local"
PORT = 1883
USER = ""
PASSWORD = ""
TOPIC_PREFIX = "birdnet"
PI_URL = "http://birdnet.local/api/recent.json?hours=1"

seen_keys: set[str] = set()

def slugify(s: str) -> str:
    return "".join(c.lower() if c.isalnum() else "-" for c in s).strip("-")

def loop(client: mqtt.Client) -> None:
    while True:
        try:
            with urllib.request.urlopen(PI_URL, timeout=10) as r:
                payload = json.loads(r.read())
            for s in payload.get("species", []):
                key = f"{s['sci']}|{s.get('last_seen','')}"
                if key in seen_keys:
                    continue
                seen_keys.add(key)
                topic = f"{TOPIC_PREFIX}/{slugify(s['sci'])}"
                client.publish(topic, json.dumps(s), qos=0, retain=False)
                print(f"published {topic}: {s.get('com')}")
        except Exception as e:
            print(f"poll error: {e}")
        time.sleep(60)

def main() -> None:
    client = mqtt.Client()
    if USER:
        client.username_pw_set(USER, PASSWORD)
    client.connect(BROKER, PORT, keepalive=60)
    client.loop_start()
    loop(client)

if __name__ == "__main__":
    main()
