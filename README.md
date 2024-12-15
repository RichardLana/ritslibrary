# ritslibrary
# API Documentation

This application is a RESTful API built with the Slim Framework and secured using JWT authentication. Below are the descriptions of the available endpoints, expected payloads, and sample responses.

## Authentication

All endpoints (except `/user/register` and `/user/login`) require a valid JWT token in the `Authorization` header:

```
Authorization: Bearer <your_token>
```

---

## Endpoints

### User Operations

#### **1. Register User**
- **Endpoint**: `POST /user/register`
- **Payload**:
  ```json
  {
    "username": "string",
    "password": "string"
  }
  ```
- **Response**:
  ```json
  {
    "status": "success",
    "message": "User registered successfully."
  }
  ```

#### **2. User Login**
- **Endpoint**: `POST /user/login`
- **Payload**:
  ```json
  {
    "username": "string",
    "password": "string"
  }
  ```
- **Response**:
  ```json
  {
    "status": "success",
    "token": "jwt_token"
  }
  ```

#### **3. Display User**
- **Endpoint**: `GET /user/display`
- **Response**:
  ```json
  {
    "status": "success",
    "data": {
      "username": "string",
      "created_at": "date_time"
    }
  }
  ```

#### **4. Update User**
- **Endpoint**: `PUT /user/update`
- **Payload**:
  ```json
  {
    "username": "string",
    "password": "string"
  }
  ```
- **Response**:
  ```json
  {
    "status": "success",
    "message": "User updated successfully."
  }
  ```

#### **5. Delete User**
- **Endpoint**: `DELETE /user/delete`
- **Response**:
  ```json
  {
    "status": "success",
    "message": "User deleted successfully."
  }
  ```

---

### Book Operations

#### **1. Display Books**
- **Endpoint**: `GET /books/displaybooks`
- **Response**:
  ```json
  {
    "status": "success",
    "data": [
      { "book_id": 1, "title": "string", "author": "string" },
      { "book_id": 2, "title": "string", "author": "string" }
    ]
  }
  ```

#### **2. Register Book**
- **Endpoint**: `POST /book/register`
- **Payload**:
  ```json
  {
    "title": "string",
    "author_id": "integer"
  }
  ```
- **Response**:
  ```json
  {
    "status": "success",
    "message": "Book registered successfully."
  }
  ```

#### **3. Update Book**
- **Endpoint**: `PUT /book/update`
- **Payload**:
  ```json
  {
    "book_id": "integer",
    "title": "string",
    "author_id": "integer"
  }
  ```
- **Response**:
  ```json
  {
    "status": "success",
    "message": "Book updated successfully."
  }
  ```

#### **4. Delete Book**
- **Endpoint**: `DELETE /book/delete`
- **Payload**:
  ```json
  {
    "book_id": "integer"
  }
  ```
- **Response**:
  ```json
  {
    "status": "success",
    "message": "Book deleted successfully."
  }
  ```

---

### Author Operations

#### **1. Display Authors**
- **Endpoint**: `GET /authors/display`
- **Response**:
  ```json
  {
    "status": "success",
    "data": [
      { "author_id": 1, "name": "string" },
      { "author_id": 2, "name": "string" }
    ]
  }
  ```

#### **2. Register Author**
- **Endpoint**: `POST /author/register`
- **Payload**:
  ```json
  {
    "name": "string"
  }
  ```
- **Response**:
  ```json
  {
    "status": "success",
    "message": "Author registered successfully."
  }
  ```

#### **3. Update Author**
- **Endpoint**: `PUT /author/update`
- **Payload**:
  ```json
  {
    "author_id": "integer",
    "name": "string"
  }
  ```
- **Response**:
  ```json
  {
    "status": "success",
    "message": "Author updated successfully."
  }
  ```

#### **4. Delete Author**
- **Endpoint**: `DELETE /author/delete`
- **Payload**:
  ```json
  {
    "author_id": "integer"
  }
  ```
- **Response**:
  ```json
  {
    "status": "success",
    "message": "Author deleted successfully."
  }
  ```

---

### Book-Author Connections

#### **1. Connect Book and Author**
- **Endpoint**: `POST /book-authors/connect`
- **Payload**:
  ```json
  {
    "book_id": "integer",
    "author_id": "integer"
  }
  ```
- **Response**:
  ```json
  {
    "status": "success",
    "message": "Book and author connected successfully."
  }
  ```

#### **2. Display Connections**
- **Endpoint**: `GET /book-authors/display`
- **Response**:
  ```json
  {
    "status": "success",
    "data": [
      { "connection_id": 1, "book_id": 1, "author_id": 1 },
      { "connection_id": 2, "book_id": 2, "author_id": 2 }
    ]
  }
  ```

#### **3. Update Connection**
- **Endpoint**: `PUT /book-authors/update`
- **Payload**:
  ```json
  {
    "connection_id": "integer",
    "book_id": "integer",
    "author_id": "integer"
  }
  ```
- **Response**:
  ```json
  {
    "status": "success",
    "message": "Connection updated successfully."
  }
  ```

#### **4. Delete Connection**
- **Endpoint**: `DELETE /book-authors/delete`
- **Payload**:
  ```json
  {
    "connection_id": "integer"
  }
  ```
- **Response**:
  ```json
  {
    "status": "success",
    "message": "Connection deleted successfully."
  }
  
