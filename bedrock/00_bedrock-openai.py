from openai import OpenAI

client = OpenAI()

response = client.chat.completions.create(
    model="openai.gpt-oss-20b-1:0",
    messages=[
        {
            "role": "user",
            "content": "Can you explain the features of Amazon Bedrock?",
        }
    ],
)

print(response.choices[0].message.content)