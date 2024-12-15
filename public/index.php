<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
require '../src/vendor/autoload.php';
$config = ['settings' => ['displayErrorDetails' => true]];
$app = new \Slim\App($config);


// Key
$key = 'hack_me';

// Middleware to verify JWT
$jwtMiddleware = function ($request, $response, $next) use ($key) {
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1];
        if (verifyToken($jwt, $key)) {
            return $next($request, $response);
        }
    }
    return $response->withStatus(401)->write(json_encode(["status" => "fail", "data" => ["title" => "Unauthorized"]]));
};

// Create JWT
function createToken($data, $key, $issuer, $audience) {
    $iat = time();
    $payload = [
        'iss' => $issuer,
        'aud' => $audience,
        'iat' => $iat,
        'exp' => $iat + 3600,
        'data' => $data
    ];
    return JWT::encode($payload, $key, 'HS256');
}

// Verify JWT
function verifyToken($token, $key) {
    try {
        return JWT::decode($token, new Key($key, 'HS256'));
    } catch (Exception $e) {
        return false; // You may want to log the error for debugging
    }
}

// DB connection
function getConnection() {
    $servername = "localhost";
    $dbusername = "root";
    $dbpassword = "";
    $dbname = "ritslibrary";
    $port = 3308;
    return new PDO("mysql:host=$servername;port=$port;dbname=$dbname", $dbusername, $dbpassword);
}

//before deleting book or author,delete first in books-authors relationship

// User registration
$app->post('/user/register', function (Request $request, Response $response) {
    $data = json_decode($request->getBody());
    if (!$data || !isset($data->username) || !isset($data->password)) {
        return $response->withStatus(400)->write(json_encode(["status" => "fail", "data" => ["title" => "Invalid input"]]));
    }

    $uname = $data->username;
    $pass = $data->password;

    try {
        $conn = getConnection();
        $sql = "INSERT INTO users (username, password) VALUES (:username, :password)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':username' => $uname, ':password' => hash("SHA256", $pass)]);
        return $response->withHeader('Content-Type', 'application/json')
                        ->write(json_encode(["status" => "success", "data" => null]));
    } catch (PDOException $e) {
        return $response->withHeader('Content-Type', 'application/json')
                        ->write(json_encode(["status" => "fail", "data" => ["title" => $e->getMessage()]]));
    }
});

// User authentication (token start)
$app->post('/user/login', function (Request $request, Response $response) use ($key) {
    $data = json_decode($request->getBody());
    if (!$data || !isset($data->username) || !isset($data->password)) {
        return $response->withStatus(400)->write(json_encode(["status" => "fail", "data" => ["title" => "Invalid input"]]));
    }

    $uname = $data->username;
    $pass = $data->password;

    try {
        $conn = getConnection();
        $sql = "SELECT * FROM users WHERE username = :username AND password = :password";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':username' => $uname, ':password' => hash("SHA256", $pass)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $token = createToken(['username' => $uname, 'userid' => $user['userid']], $key, 'http://library.org', 'http://library.com');
            return $response->withHeader('Content-Type', 'application/json')
                            ->write(json_encode(["status" => "success", "token" => $token]));
        } else {
            return $response->withStatus(401)
                            ->withHeader('Content-Type', 'application/json')
                            ->write(json_encode(["status" => "fail", "data" => ["title" => "Invalid credentials"]]));
        }
    } catch (PDOException $e) {
        return $response->withHeader('Content-Type', 'application/json')
                        ->write(json_encode(["status" => "fail", "data" => ["title" => $e->getMessage()]]));
    }
});

// In-memory storage for invalid tokens
$app->get('/user/display', function (Request $request, Response $response) use ($key) {
    // Extract the Authorization header
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1]; // Extract the token

        try {
            // Connect to the database
            $conn = getConnection();

            // Check if the token is already invalidated
            $checkSql = "SELECT COUNT(*) FROM invalid_tokens WHERE token = :token";
            $stmt = $conn->prepare($checkSql);
            $stmt->execute([':token' => $jwt]);
            $isInvalid = $stmt->fetchColumn();

            if ($isInvalid) {
                // Token is already invalidated
                return $response->withStatus(401)
                                ->withHeader('Content-Type', 'application/json')
                                ->write(json_encode([
                                    "status" => "fail",
                                    "data" => ["title" => "Token has already been used"]
                                ]));
            }

            // Verify token validity
            $decoded = verifyToken($jwt, $key);
            if ($decoded) {
                // Extract user ID from token (optional validation for access)
                $userid = $decoded->data->userid;

                // Query all user information from the database
                $userSql = "SELECT userid, username FROM users";
                $stmt = $conn->prepare($userSql);
                $stmt->execute();
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if ($users) {
                    // Mark token as invalid (revoke the token)
                    $revokeSql = "INSERT INTO invalid_tokens (token) VALUES (:token)";
                    $stmt = $conn->prepare($revokeSql);
                    $stmt->execute([':token' => $jwt]);

                    // Generate a new token
                    $newToken = createToken(['userid' => $userid, 'username' => $decoded->data->username], $key, 'http://library.org', 'http://library.com');

                    // Return all user information with the new token
                    return $response->withHeader('Content-Type', 'application/json')
                                    ->write(json_encode([
                                        "status" => "success",
                                        "data" => $users,
                                        "new_token" => $newToken
                                    ]));
                } else {
                    return $response->withStatus(404)
                                    ->withHeader('Content-Type', 'application/json')
                                    ->write(json_encode([
                                        "status" => "fail",
                                        "data" => ["title" => "No users found"]
                                    ]));
                }
            }
        } catch (PDOException $e) {
            return $response->withHeader('Content-Type', 'application/json')
                            ->write(json_encode([
                                "status" => "fail",
                                "data" => ["title" => $e->getMessage()]
                            ]));
        }
    }

    // If no valid token is provided
    return $response->withStatus(401)
                    ->withHeader('Content-Type', 'application/json')
                    ->write(json_encode([
                        "status" => "fail",
                        "data" => ["title" => "Unauthorized"]
                    ]));
});

// Update user by ID and generate a new token for the update
$app->put('/user/update', function (Request $request, Response $response) use ($key) {
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1];
        $decoded = verifyToken($jwt, $key);

        if ($decoded) {
            $userid = $decoded->data->userid; // Get user ID from decoded token
            $data = json_decode($request->getBody());
            if (!$data || !isset($data->username) || !isset($data->password)) {
                return $response->withStatus(400)->write(json_encode(["status" => "fail", "data" => ["title" => "Invalid input"]]));
            }

            $uname = $data->username;
            $pass = $data->password;

            try {
                $conn = getConnection();
                $sql = "UPDATE users SET username = :username, password = :password WHERE userid = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([ ':username' => $uname, ':password' => hash("SHA256", $pass), ':id' => $userid ]);

                // Generate a new token for the user
                $newToken = createToken(['username' => $uname, 'userid' => $userid], $key, 'http://library.org', 'http://library.com');

                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "success", "token" => $newToken]));
            } catch (PDOException $e) {
                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "fail", "data" => ["title" => $e->getMessage()]]));
            }
        }
    }
    return $response->withStatus(401)
                    ->withHeader('Content-Type', 'application/json')
                    ->write(json_encode(["status" => "fail", "data" => ["title" => "Unauthorized"]]));
});

// Delete user by ID and generate a new token after the operation
$app->delete('/user/delete', function (Request $request, Response $response) use ($key) {
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1];
        $decoded = verifyToken($jwt, $key);

        if ($decoded) {
            $userid = $decoded->data->userid; // Get user ID from decoded token

            try {
                $conn = getConnection();
                $sql = "DELETE FROM users WHERE userid = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $userid]);

                // Generate a new token for the user
                $newToken = createToken(['userid' => $userid], $key, 'http://library.org', 'http://library.com');

                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode([ "status" => "success", "data" => null, "token" => $newToken ]));
            } catch (PDOException $e) {
                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode([ "status" => "fail", "data" => ["title" => $e->getMessage()]]));
            }
        }
    }
    return $response->withStatus(401)
                    ->withHeader('Content-Type', 'application/json')
                    ->write(json_encode([ "status" => "fail", "data" => ["title" => "Unauthorized"]]));
});

// =================== Book-Authors Relationship ===================
// Create a Book-Author Relationship
$app->post('/book-authors/connect', function (Request $request, Response $response) use ($key) {
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1];
        $decoded = verifyToken($jwt, $key);

        if ($decoded) {
            // Get the request body and decode it
            $data = json_decode($request->getBody());

            // Check if json_decode was successful and the necessary fields are present
            if ($data && isset($data->bookid) && isset($data->authorid)) {
                $bookid = $data->bookid;
                $authorid = $data->authorid;

                try {
                    $conn = getConnection();
                    $sql = "INSERT INTO book_authors (bookid, authorid) VALUES (:bookid, :authorid)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':bookid' => $bookid,
                        ':authorid' => $authorid,
                    ]);

                    // Generate a new token after the insertion
                    $newToken = createToken(['bookid' => $bookid, 'authorid' => $authorid], $key, 'http://library.org', 'http://library.com');

                    return $response->withHeader('Content-Type', 'application/json')
                                    ->write(json_encode(["status" => "success", "token" => $newToken, "data" => null]));
                } catch (PDOException $e) {
                    return $response->withHeader('Content-Type', 'application/json')
                                    ->write(json_encode(["status" => "fail", "data" => ["title" => $e->getMessage()]]));
                }
            } else {
                // Return error if required fields are missing or JSON is invalid
                return $response->withStatus(400)
                                 ->withHeader('Content-Type', 'application/json')
                                 ->write(json_encode([
                                     "status" => "fail",
                                     "data" => ["title" => "Missing 'bookid' or 'authorid' in request body"]
                                 ]));
            }
        }
    }
    return $response->withStatus(401)->write(json_encode(["status" => "fail", "data" => ["title" => "Unauthorized"]]));
});


// Get All Book-Authors
$app->get('/book-authors/display', function (Request $request, Response $response) use ($key) {
    try {
        $conn = getConnection();
        $sql = "SELECT * FROM book_authors";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $bookAuthors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $newToken = createToken(['operation' => 'fetch_book_authors'], $key, 'http://library.org', 'http://library.com');

        return $response->withHeader('Content-Type', 'application/json')
                        ->write(json_encode([
                            "status" => "success",
                            "data" => $bookAuthors,
                            "token" => $newToken
                        ]));
    } catch (PDOException $e) {
        return $response->withHeader('Content-Type', 'application/json')
                        ->write(json_encode([
                            "status" => "fail",
                            "data" => ["title" => $e->getMessage()]
                        ]));
    }
});

// Update a Book-Author Relationship
$app->put('/book-authors/update', function (Request $request, Response $response) use ($key) {
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1];
        $decoded = verifyToken($jwt, $key);

        if ($decoded) {
            $data = json_decode($request->getBody());

            // Debugging output to check what data is coming in
            error_log("Decoded data: " . print_r($data, true)); // Log the decoded data

            // Check if all required fields are present
            if (isset($data->collectionid) && isset($data->bookid) && isset($data->authorid)) {
                $collectionid = $data->collectionid;
                $bookid = $data->bookid;
                $authorid = $data->authorid;

                try {
                    $conn = getConnection();
                    $sql = "UPDATE book_authors SET bookid = :bookid, authorid = :authorid WHERE collectionid = :collectionid";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':bookid' => $bookid,
                        ':authorid' => $authorid,
                        ':collectionid' => $collectionid
                    ]);

                    $newToken = createToken(['collectionid' => $collectionid, 'bookid' => $bookid, 'authorid' => $authorid], $key, 'http://library.org', 'http://library.com');

                    return $response->withHeader('Content-Type', 'application/json')
                                    ->write(json_encode(["status" => "success", "token" => $newToken, "data" => null]));
                } catch (PDOException $e) {
                    return $response->withHeader('Content-Type', 'application/json')
                                    ->write(json_encode(["status" => "fail", "data" => ["title" => $e->getMessage()]]));
                }
            } else {
                return $response->withStatus(400)
                                 ->withHeader('Content-Type', 'application/json')
                                 ->write(json_encode([
                                     "status" => "fail",
                                     "data" => ["title" => "Missing 'collectionid', 'bookid', or 'authorid' in request body"]
                                 ]));
            }
        }
    }
    return $response->withStatus(401)->write(json_encode(["status" => "fail", "data" => ["title" => "Unauthorized"]]));
});


// Delete a Book-Author Relationship
$app->delete('/book-authors/delete', function (Request $request, Response $response) use ($key) {
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1];
        $decoded = verifyToken($jwt, $key);

        if ($decoded) {
            $data = json_decode($request->getBody());
            $collectionid = $data->collectionid;

            try {
                $conn = getConnection();
                $sql = "DELETE FROM book_authors WHERE collectionid = :collectionid";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':collectionid' => $collectionid]);

                $newToken = createToken(['collectionid' => $collectionid], $key, 'http://library.org', 'http://library.com');

                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "success", "token" => $newToken, "data" => null]));
            } catch (PDOException $e) {
                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "fail", "data" => ["title" => $e->getMessage()]]));
            }
        }
    }
    return $response->withStatus(401)->write(json_encode(["status" => "fail", "data" => ["title" => "Unauthorized"]]));
});

// =================== Books ===================

// Get All Books
$app->get('/books/displaybooks', function (Request $request, Response $response) use ($key) {
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1];
        $decoded = verifyToken($jwt, $key);

        if ($decoded) {
            try {
                $conn = getConnection();
                $sql = "SELECT * FROM books";
                $stmt = $conn->prepare($sql);
                $stmt->execute();
                $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $newToken = createToken(['bookid' => $decoded->data->bookid, 'title' => $decoded->data->title], $key, 'http://library.org', 'http://library.com');

                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode([
                                    "status" => "success", 
                                    "data" => $books,
                                    "token" => $newToken
                                ]));
            } catch (PDOException $e) {
                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "fail", "data" => ["title" => $e->getMessage()]]));
            }
        }
    }
    return $response->withStatus(401)->write(json_encode(["status" => "fail", "data" => ["title" => "Unauthorized"]]));
});

// Create a Book
$app->post('/book/register', function (Request $request, Response $response) use ($key) {
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1];
        $decoded = verifyToken($jwt, $key);
        if ($decoded) {
            $data = json_decode($request->getBody());
            $title = $data->title;
            $authorIds = $data->authorids;

            try {
                $conn = getConnection();
                $sql = "INSERT INTO books (title) VALUES (:title)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':title' => $title]);

                $bookId = $conn->lastInsertId();

                if (!empty($authorIds) && is_array($authorIds)) {
                    foreach ($authorIds as $authorId) {
                        $sql = "INSERT INTO book_authors (bookid, authorid) VALUES (:bookid, :authorid)";
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([':bookid' => $bookId, ':authorid' => $authorId]);
                    }
                } else {
                    return $response->withHeader('Content-Type', 'application/json')
                                    ->write(json_encode(["status" => "fail", "data" => ["title" => "Author IDs missing or not an array"]]));
                }

                $newToken = createToken(['bookid' => $bookId, 'title' => $title], $key, 'http://library.org', 'http://library.com');
                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "success", "token" => $newToken, "data" => null]));
            } catch (PDOException $e) {
                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "fail", "data" => ["title" => $e->getMessage()]]));
            }
        }
    }
    return $response->withStatus(401)->write(json_encode(["status" => "fail", "data" => ["title" => "Unauthorized"]]));
});

// Update a Book
$app->put('/book/update', function (Request $request, Response $response) use ($key) {
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1];
        $decoded = verifyToken($jwt, $key);

        if ($decoded) {
            $data = json_decode($request->getBody());
            $id = $data->bookid; // Get the book ID from the JSON body
            $title = $data->title;
            $authorId = $data->authorid; // Match the variable name with the author's ID

            try {
                $conn = getConnection();
                $sql = "UPDATE books SET title = :title WHERE bookid = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':title' => $title, ':id' => $id]);

                // Update the book-author relationship
                $sql = "UPDATE book_authors SET authorid = :authorid WHERE bookid = :bookid";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':authorid' => $authorId, ':bookid' => $id]);

                // Generate a new token after a successful update
                $newToken = createToken(['bookid' => $id, 'title' => $title, 'authorid' => $authorId], $key, 'http://library.org', 'http://library.com');

                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "success", "token" => $newToken, "data" => null]));
            } catch (PDOException $e) {
                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "fail", "data" => ["title" => $e->getMessage()]]));
            }
        }
    }
    return $response->withStatus(401)->write(json_encode(["status" => "fail", "data" => ["title" => "Unauthorized"]]));
})->add($jwtMiddleware);

// Delete a Book
$app->delete('/book/delete', function (Request $request, Response $response) use ($key) {
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1];
        $decoded = verifyToken($jwt, $key);

        if ($decoded) {
            $body = json_decode($request->getBody(), true);
            $id = $body['bookid'] ?? null; // Retrieve the book ID from the JSON body

            if (!$id) {
                return $response->withStatus(400)->write(json_encode(["status" => "fail", "data" => ["title" => "Book ID is required"]]));
            }

            try {
                $conn = getConnection();
                // First, delete the relationship in the book-authors table
                $sql = "DELETE FROM book_authors WHERE bookid = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $id]);

                // Then delete the book
                $sql = "DELETE FROM books WHERE bookid = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $id]);

                // Generate a new token after successful deletion (if needed)
                $newToken = createToken(['bookid' => $decoded->data->id], $key, 'http://library.org', 'http://library.com');

                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "success", "token" => $newToken, "data" => null]));
            } catch (PDOException $e) {
                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "fail", "data" => ["title" => $e->getMessage()]]));
            }
        }
    }
    return $response->withStatus(401)->write(json_encode(["status" => "fail", "data" => ["title" => "Unauthorized"]]));
})->add($jwtMiddleware);



// =================== Authors ===================

// Get All Authors
$app->get('/authors/display', function (Request $request, Response $response) use ($key) { // Use the secret key defined earlier
    try {
        $conn = getConnection();
        $sql = "SELECT * FROM authors";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $authors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Generate a new token for the operation (not for each author)
        $newToken = createToken(['action' => 'read_authors'], $key, 'http://library.org', 'http://library.com');

        return $response->withHeader('Content-Type', 'application/json')
                        ->write(json_encode([
                            "status" => "success", 
                            "data" => $authors,
                            "token" => $newToken // Include the new token for the operation
                        ]));
    } catch (PDOException $e) {
        return $response->withHeader('Content-Type', 'application/json')
                        ->write(json_encode([
                            "status" => "fail", 
                            "data" => ["title" => $e->getMessage()]
                        ]));
    }
});


// Create an Author
$app->post('/author/register', function (Request $request, Response $response) use ($key) {
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1];
        $decoded = verifyToken($jwt, $key);

        if ($decoded) {
            $data = json_decode($request->getBody());
            $name = $data->name;

            try {
                $conn = getConnection();
                $sql = "INSERT INTO authors (name) VALUES (:name)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':name' => $name]);

                $authorId = $conn->lastInsertId();
                $newToken = createToken(['authorid' => $authorId, 'name' => $name], $key, 'http://library.org', 'http://library.com');

                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "success", "token" => $newToken, "data" => null]));
            } catch (PDOException $e) {
                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "fail", "data" => ["title" => $e->getMessage()]]));
            }
        }
    }
    return $response->withStatus(401)->write(json_encode(["status" => "fail", "data" => ["title" => "Unauthorized"]]));
});

// Update an Author
$app->put('/author/update', function (Request $request, Response $response) use ($key) {
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1];
        $decoded = verifyToken($jwt, $key);

        if ($decoded) {
            $data = json_decode($request->getBody());
            $authorid = $data->authorid;
            $name = $data->name;

            try {
                $conn = getConnection();
                $sql = "UPDATE authors SET name = :name WHERE authorid = :authorid";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':name' => $name, ':authorid' => $authorid]);

                $newToken = createToken(['authorid' => $authorid, 'name' => $name], $key, 'http://library.org', 'http://library.com');

                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "success", "token" => $newToken, "data" => null]));
            } catch (PDOException $e) {
                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "fail", "data" => ["title" => $e->getMessage()]]));
            }
        }
    }
    return $response->withStatus(401)->write(json_encode(["status" => "fail", "data" => ["title" => "Unauthorized"]]));
});

// Delete an Author
$app->delete('/author/delete', function (Request $request, Response $response) use ($key) {
    $authHeader = $request->getHeader('Authorization');
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader[0], $matches)) {
        $jwt = $matches[1];
        $decoded = verifyToken($jwt, $key);

        if ($decoded) {
            $data = json_decode($request->getBody());
            $authorid = $data->authorid;

            try {
                $conn = getConnection();
                $sql = "DELETE FROM authors WHERE authorid = :authorid";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':authorid' => $authorid]);

                $newToken = createToken(['authorid' => $authorid], $key, 'http://library.org', 'http://library.com');

                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "success", "token" => $newToken, "data" => null]));
            } catch (PDOException $e) {
                return $response->withHeader('Content-Type', 'application/json')
                                ->write(json_encode(["status" => "fail", "data" => ["title" => $e->getMessage()]]));
            }
        }
    }
    return $response->withStatus(401)->write(json_encode(["status" => "fail", "data" => ["title" => "Unauthorized"]]));
});

$app->run();
?>
