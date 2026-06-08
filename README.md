# Personal Finance AI Assistant

> **Setup:** To install and run this project locally, follow **[SETUP.md](SETUP.md)**.

An AI-powered personal finance assistant that allows users to analyze spending, manage budgets, and interact with their financial data using natural language.

Built with Laravel, Vue (Inertia), and an AI routing layer powered by LLMs (OpenAI-compatible interface).

## Overview

This application simulates a modern AI financial assistant similar to products like Copilot or Cleo.

Users can:

- Ask questions about their spending
- Track budgets by category
- Upload receipts (mock OCR supported)
- Store personal financial preferences (memory system)
- Get financial summaries and insights

The system combines:

- Deterministic backend logic (Laravel services)
- AI-based intent classification (LLM routing layer)
- Persistent user memory
- Structured financial analytics

## System Architecture

The system follows a hybrid AI + backend architecture:

```
User (Vue Chat UI)
        ↓
AiRouterService (LLM Intent Classification)
        ↓
PromptBuilderService (System Prompt + Memory Context)
        ↓
LLM (OpenAI-compatible model)
        ↓
Intent Router
   ├── SpendingService
   ├── BudgetService
   ├── InsightService
   ├── MemoryService
   ├── FinancialSummaryService
        ↓
ResponseFormatterService
        ↓
Frontend (Inertia Vue Chat UI)
```

## Key Design Decisions

### 1. AI as a routing layer only

The LLM is **not** responsible for calculations. It only classifies user intent.

All financial calculations are handled by Laravel services.

### 2. Backend is source of truth

All financial data (transactions, budgets, memory) is stored and processed in Laravel.

This ensures:

- consistency
- scalability
- predictable outputs

### 3. Hybrid memory system

User preferences (e.g. “ignore rent”, “salary date”) are stored and injected into:

- AI prompts
- backend filters

### 4. Deterministic financial logic

Spending, budgets, and summaries are computed using database queries — not AI guesses.

## Features Implemented

### AI Chat Interface

Natural language interface for financial queries.

### Spending Analytics

- Total spending
- Spending by category
- Monthly breakdown

### Budget Tracking

- Category budgets
- Remaining balance
- Overspending detection

### Memory System

Stores user preferences such as:

- excluded categories
- salary dates
- custom rules

### Receipt Upload (Mock OCR)

- Image upload supported
- Extracts structured transaction data (mocked if no API key)

### Financial Summary

Generates a high-level overview of user finances.

## Limitations & Trade-offs

### 1. OCR is mocked

Receipt extraction uses simulated logic unless an external AI vision API is configured.

### 2. Intent classification depends on LLM

While fallback rules exist, accuracy depends on model output.

### 3. No real banking integration

All financial data is simulated via seeded transactions.

## Assumptions

- Users have pre-existing transaction data (seeded for demo)
- AI is used for classification, not computation
- System operates in a single currency context

## Tech Stack

- Laravel 13
- Vue 3 (Inertia.js)
- MySQL
- OpenAI-compatible LLM API
- TailwindCSS (UI)
- Carbon (date handling)

## Example Use Cases

- “How much did I spend on food last month?”
- “What is my budget for shopping?”
- “Summarize my finances”
- “What is this Uber charge?”
- “I want to save more money — what should I cut?”

## What This Project Demonstrates

This project demonstrates:

- AI system design (routing vs reasoning separation)
- Scalable backend architecture
- Financial data modeling
- Memory-aware AI personalization
- Handling messy real-world inputs
- Trade-offs between AI and deterministic systems

## Future Improvements

- Real OCR integration (GPT-4o Vision or AWS Textract)
- Bank API integration
- Advanced anomaly detection models
- Multi-currency support
- Mobile app interface

## Summary

This is not a chatbot.

It is a **financial reasoning system with an AI interface layer**.
