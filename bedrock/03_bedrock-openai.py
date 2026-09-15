# Output strutturato JSON
# In questo esempio, utilizziamo il modello openai.gpt-oss-20b-1:0 per analizzare un ticket di assistenza e restituire un output strutturato in formato JSON.

from openai import OpenAI
import json

client = OpenAI()

ticket = """
Il cliente non riesce ad accedere al portale da questa mattina.
Dopo il login compare un errore 503. Il problema blocca il lavoro
di tutto l'ufficio amministrativo.
"""

response = client.chat.completions.create(
    model="openai.gpt-oss-20b-1:0",
    messages=[
        {
            "role": "system",
            "content": """
Analizza ticket di assistenza.
Restituisci esclusivamente JSON valido con i campi:
categoria, priorita, riepilogo, azione_consigliata.
""",
        },
        {"role": "user", "content": ticket},
    ],
    temperature=0,
    max_tokens=250,
)

result = response.choices[0].message.content
print(result)

data = json.loads(result)
print("\nPriorità:", data["priorita"])