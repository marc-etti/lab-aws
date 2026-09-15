# Conversazione multi-turn
# In questo esempio, utilizziamo il modello openai.gpt-oss-20b-1:0 per generare risposte a domande poste dall'utente in una conversazione multi-turn.

from openai import OpenAI

client = OpenAI()

messages = [
    {
        "role": "system",
        "content": "Sei un tutor AWS. Rispondi sinteticamente in italiano.",
    }
]

while True:
    question = input("\nUtente: ")

    if question.lower() in {"exit", "quit"}:
        break

    messages.append({"role": "user", "content": question})

    response = client.chat.completions.create(
        model="openai.gpt-oss-20b-1:0",
        messages=messages,
        temperature=0.3,
        max_tokens=250,
    )

    answer = response.choices[0].message.content
    messages.append({"role": "assistant", "content": answer})

    print(f"\nAssistente: {answer}")